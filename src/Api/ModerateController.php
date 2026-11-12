<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Access;
use ErnestDefoe\Stances\Presenter;
use ErnestDefoe\Stances\Refusal;
use ErnestDefoe\Stances\Votes;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST   /api/stances/{id}/reset     moderators: wipe the votes and their history
 * POST   /api/stances/{id}/feature   {on}: show it above the discussion (the starter or a moderator)
 * POST   /api/stances/{id}/badge     {on}: make the discussion's badges from it (the starter or a moderator)
 */
class ModerateController extends Handler
{
    public function __construct(
        private readonly Votes $votes,
        private readonly Presenter $presenter,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $block = Find::visible($request, $actor);
        $data = $this->presenter->one($block, $actor);
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/reset')) {
            if (! $data['canModerate']) {
                self::deny();
            }
            $block = $this->votes->reset($block);
        } else {
            $discussion = $block->discussion;
            if (! $discussion || ! $block->post_id || ! Access::canSteer($actor, $discussion)) {
                self::deny();
            }
            $on = Input::bool(self::body($request)['on'] ?? null, true);
            $column = str_ends_with($path, '/badge') ? 'stance_badge_id' : 'stance_featured_id';

            if ($on && $column === 'stance_badge_id' && ! $block->makesBadges()) {
                throw new Refusal('stances_no_badges');
            }

            if ($on) {
                $discussion->setAttribute($column, $block->id);
            } elseif ((int) $discussion->getAttribute($column) === $block->id) {
                $discussion->setAttribute($column, null);
            }
            $discussion->save();
        }

        return self::show($request, $block);
    }
}
