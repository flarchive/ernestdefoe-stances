<?php

/*
 * Stances: one-click blocks anywhere in a post (Agree/Disagree, Yes/No,
 * This-or-That, Rating), and the side each member took shown beside their
 * replies.
 *
 * A block starts as a draft from the composer's dialog and joins its post when
 * the post is saved with its `[stance=N]` marker (Listener\AttachBlocks). Votes
 * are taken under a row lock and recounted (Votes); every vote, change and
 * removal is logged, so a badge can show the side a member took when they
 * posted as well as the side they're on now (Sides). Posts get their blocks and
 * badges in a fixed number of queries per response (Api\PostBatch).
 *
 * 🚨 String literals only below, never a class constant read at load time
 * (Settings::KEY): it autoloads during boot and fatals every request while a
 * deploy is swapping composer's autoload map. Constants inside closures are fine.
 */

use ErnestDefoe\Stances\Access;
use ErnestDefoe\Stances\Api;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Console\PruneCommand;
use ErnestDefoe\Stances\Http\PageContent;
use ErnestDefoe\Stances\Listener;
use ErnestDefoe\Stances\Notification\MilestoneBlueprint;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Event\Deleting as DiscussionDeleting;
use Flarum\Extend;
use Flarum\Post\Event\Deleting as PostDeleting;
use Flarum\Post\Event\Saving as PostSaving;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Deleting as UserDeleting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use s9e\TextFormatter\Configurator;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The page, the dialog and the voters list are a chunk in here; undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/stances', 'stances', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    // `[stance=12]` becomes an empty holder the forum mounts the block into. Inside a code block it
    // stays text. The marker's id is all it carries: what shows comes from the post's own blocks.
    (new Extend\Formatter())
        ->configure(function (Configurator $config) {
            $config->BBCodes->addCustom('[STANCE={UINT}]', '<div class="StanceHolder" data-stance="{UINT}"></div>');
        }),

    (new Extend\Routes('api'))
        ->get('/stances', 'stances.index', Api\ListController::class)
        ->post('/stances', 'stances.create', Api\SaveController::class)
        ->post('/stances/images', 'stances.images', Api\ImageController::class)
        ->get('/stances/{id:\d+}', 'stances.show', Api\ListController::class)
        ->patch('/stances/{id:\d+}', 'stances.update', Api\SaveController::class)
        ->delete('/stances/{id:\d+}', 'stances.delete', Api\ModerateController::class)
        ->post('/stances/{id:\d+}/vote', 'stances.vote', Api\VoteController::class)
        ->delete('/stances/{id:\d+}/vote', 'stances.unvote', Api\VoteController::class)
        ->get('/stances/{id:\d+}/voters', 'stances.voters', Api\VotersController::class)
        ->get('/stances/{id:\d+}/sides', 'stances.sides', Api\SidesController::class)
        ->post('/stances/{id:\d+}/reset', 'stances.reset', Api\ModerateController::class)
        ->post('/stances/{id:\d+}/feature', 'stances.feature', Api\ModerateController::class)
        ->post('/stances/{id:\d+}/badge', 'stances.badge', Api\ModerateController::class),

    new Extend\ApiResource(Api\StanceResource::class),

    (new Extend\ModelVisibility(Block::class))
        ->scope(\ErnestDefoe\Stances\Scope\BlockVisibility::class),

    // A post's blocks and its author's badge, batched for the whole response (Api\PostBatch).
    (new Extend\ApiResource(PostResource::class))
        ->fields(fn () => [
            // Not on discussion lists: their first posts are excerpts, never shown with blocks or badges.
            Schema\Arr::make('stanceBlocks')
                ->visible(fn (Post $post, Context $context) => $post->type === 'comment' && ! $context->listing(DiscussionResource::class))
                ->get(fn (Post $post, Context $context) => resolve(Api\PostBatch::class)->blocks($post, $context->request)),
            Schema\Arr::make('stanceSide')
                ->nullable()
                ->visible(fn (Post $post, Context $context) => $post->type === 'comment' && ! $context->listing(DiscussionResource::class))
                ->get(fn (Post $post, Context $context) => resolve(Api\PostBatch::class)->side($post, $context->request)),
        ]),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(Api\DiscussionFields::class),

    // The viewer's own permission (their groups are already loaded: no query), and how many open
    // blocks they haven't voted on (members only, one query, once per page load).
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Str::make('stancesTypes')
                ->get(fn () => implode(',', resolve(\ErnestDefoe\Stances\Settings::class)->types())),
            Schema\Boolean::make('stancesCanCreate')
                ->get(fn ($forum, Context $context) => Access::canCreateAnywhere($context->getActor())),
            Schema\Integer::make('stancesNeedVote')
                ->visible(fn ($forum, Context $context) => ! $context->getActor()->isGuest()
                    && (bool) resolve(SettingsRepositoryInterface::class)->get('ernestdefoe-stances.nav', true))
                // The same query as the Needs tab (NeedsVote), so the count is always one you can clear.
                ->get(fn ($forum, Context $context) => resolve(\ErnestDefoe\Stances\NeedsVote::class)->count($context->getActor())),
        ]),

    (new Extend\Notification())
        ->type(MilestoneBlueprint::class, ['alert'])
        ->type(\ErnestDefoe\Stances\Notification\ClosedBlueprint::class, ['alert']),

    (new Extend\Settings())
        ->default('ernestdefoe-stances.type_agree', true)
        ->default('ernestdefoe-stances.type_yesno', true)
        ->default('ernestdefoe-stances.type_pick', true)
        ->default('ernestdefoe-stances.type_rating', true)
        ->default('ernestdefoe-stances.max_per_post', 3)
        ->default('ernestdefoe-stances.results', 'voted')
        ->default('ernestdefoe-stances.allow_change', true)
        ->default('ernestdefoe-stances.allow_remove', true)
        ->default('ernestdefoe-stances.badges', true)
        ->default('ernestdefoe-stances.badge_rule', 'auto')
        ->default('ernestdefoe-stances.changed_tag', true)
        ->default('ernestdefoe-stances.nav', true)
        ->default('ernestdefoe-stances.list_icon', false)
        ->default('ernestdefoe-stances.closed_alert', false)
        ->serializeToForum('stancesMaxPerPost', 'ernestdefoe-stances.max_per_post', 'intval')
        ->serializeToForum('stancesResults', 'ernestdefoe-stances.results')
        ->serializeToForum('stancesAllowChange', 'ernestdefoe-stances.allow_change', 'boolval')
        ->serializeToForum('stancesAllowRemove', 'ernestdefoe-stances.allow_remove', 'boolval')
        ->serializeToForum('stancesBadges', 'ernestdefoe-stances.badges', 'boolval')
        ->serializeToForum('stancesNav', 'ernestdefoe-stances.nav', 'boolval')
        ->serializeToForum('stancesChangedTag', 'ernestdefoe-stances.changed_tag', 'boolval')
        ->serializeToForum('stancesClosedAlert', 'ernestdefoe-stances.closed_alert', 'boolval'),

    (new Extend\Event())
        ->listen(PostSaving::class, Listener\AttachBlocks::class)
        // The foreign keys do this too, where the database enforces them (SQLite may not).
        ->listen(PostDeleting::class, [Listener\Cleanup::class, 'post'])
        ->listen(DiscussionDeleting::class, [Listener\Cleanup::class, 'discussion'])
        ->listen(UserDeleting::class, [Listener\Cleanup::class, 'user'])
        // fof/merge-discussions moves posts with plain updates: blocks follow their posts.
        ->listen('FoF\\MergeDiscussions\\Events\\DiscussionWasMerged', [Listener\FollowPosts::class, 'handle'])
        ->listen(\ErnestDefoe\Stances\Event\Voted::class, \ErnestDefoe\Stances\Badges\EvaluateStancesBadges::class)
        ->listen(\ErnestDefoe\Stances\Event\BlocksPosted::class, \ErnestDefoe\Stances\Badges\EvaluateStancesBadges::class),

    // Two FoF Badges metrics (stances voted, stances created); the listener does nothing without fof/badges.
    (new Extend\ServiceProvider())
        ->register(\ErnestDefoe\Stances\Badges\BadgesServiceProvider::class),

    (new Extend\Console())
        ->command(PruneCommand::class)
        ->schedule(PruneCommand::class, function (ScheduledEvent $event) {
            $event->daily()->withoutOverlapping();
        })
        ->command(\ErnestDefoe\Stances\Console\TickCommand::class)
        ->schedule(\ErnestDefoe\Stances\Console\TickCommand::class, function (ScheduledEvent $event) {
            $event->everyFiveMinutes()->withoutOverlapping();
        }),

    // A member's votes and the blocks they added in their data export; erased with them.
    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())->addType(\ErnestDefoe\Stances\Gdpr\StancesData::class),
        ]),
];
