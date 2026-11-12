<?php

namespace ErnestDefoe\Stances\Api;

use Carbon\Carbon;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Listener\Cleanup;
use ErnestDefoe\Stances\NeedsVote;
use ErnestDefoe\Stances\Presenter;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Post\Post;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * The `stances` JSON:API type.
 *
 * GET    /api/stances?filter[tab]=trending|new|closing|needs&filter[type]=…&page[offset]=N
 *        The Stances page: blocks in posts the viewer can see, 20 at a time.
 * GET    /api/stances?filter[post]=N   A post's blocks, hidden ones included, for whoever may edit it.
 * GET    /api/stances/{id}             A block the viewer can see (Find::block).
 * POST   /api/stances                  A draft block (StanceWriter::create).
 * PATCH  /api/stances/{id}             Edit, close, reopen, make anonymous (StanceWriter::update).
 * DELETE /api/stances/{id}             The author's draft, or any block for a moderator.
 *
 * Voting, reset, feature, badge, sides and voters stay their own routes and answer with this
 * resource's Show document. Everything the forum reads comes from Presenter, so results are left
 * out until the viewer may see them, whichever way a block is fetched. As a notification's subject
 * a block carries only its question and place.
 *
 * @extends AbstractDatabaseResource<Block>
 */
class StanceResource extends AbstractDatabaseResource
{
    public const PAGE = 20;
    public const TRENDING_HOURS = 48;
    public const RECENT_HOURS = 6;

    /** The attributes the dialog and the block menu send. */
    private const INPUTS = ['blockType', 'labels', 'images', 'results', 'allowChange', 'allowRemove', 'anonymous', 'badges', 'closesAt', 'closed'];

    /** What Presenter says about a block, as attributes. */
    private const PRESENTED = [
        'blockType', 'labels', 'images', 'results', 'allowChange', 'allowRemove', 'anonymous', 'badges',
        'closesAt', 'closedAt', 'closed', 'hidden', 'voteCount', 'resultsVisible', 'counts', 'average', 'mine',
        'canVote', 'canEdit', 'canModerate', 'canClose', 'canViewVoters', 'canSteer',
    ];

    /** Only on the Stances page (StanceBatch). */
    private const LISTED = ['discussion', 'postNumber', 'author', 'votesToday'];

    // Built on every API request that resolves resources: only what reads need. The writer
    // (images, disk) and the cleanup are resolved when a write happens.
    public function __construct(
        private readonly StanceBatch $batch,
        private readonly NeedsVote $needs,
    ) {
    }

    public function type(): string
    {
        return 'stances';
    }

    public function model(): string
    {
        return Block::class;
    }

    /** @param Builder<Block> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        // The listing scopes itself (a post's hidden blocks are listed for its editors).
        if (! $context->listing(self::class)) {
            $query->whereVisibleTo($context->getActor());
        }
    }

    /** @param Context $context */
    public function find(string $id, BaseContext $context): ?object
    {
        return Find::block((int) $id, $context->getActor());
    }

    public function endpoints(): array
    {
        return [
            tap(Endpoint\Index::make()->paginate(self::PAGE, self::PAGE), function (Endpoint\Index $index) {
                $index->paginationResolver = fn (BaseContext $context) => new PeekPagination($context, self::PAGE, self::PAGE);
            })
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters) => $this->listing($query, $pagination, $context, $filters)),
            Endpoint\Show::make(),
            Endpoint\Create::make()->authenticated(),
            Endpoint\Update::make()->authenticated(),
            Endpoint\Delete::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        // `type` is reserved in JSON:API, so the block's type is `blockType` here.
        $presented = fn (string $key) => fn (Block $block, Context $context) => $this->batch->get($block, $context, $key === 'blockType' ? 'type' : $key);
        // A notification's subject (or anything else including a block) gets the three plain columns only.
        $own = fn (Block $block, Context $context) => $context->collection instanceof self;
        $input = fn ($field) => $field->writable()->set(fn () => null);

        $fields = [
            // Plain columns: also read by notifications.
            $input(Schema\Attribute::make('question')),
            Schema\Integer::make('postId')->property('post_id')->nullable(),
            Schema\Integer::make('discussionId')->property('discussion_id')->nullable()
                ->writableOnCreate()->set(fn () => null),
        ];

        foreach (self::PRESENTED as $name) {
            $field = Schema\Attribute::make($name)->visible($own)->get($presented($name));
            $fields[] = in_array($name, self::INPUTS, true) ? $input($field) : $field;
        }
        foreach (self::LISTED as $name) {
            $fields[] = Schema\Attribute::make($name)
                ->visible(fn (Block $block, Context $context) => $context->listing(self::class))
                ->get($presented($name));
        }

        return $fields;
    }

    /** @param Builder<Block> $query */
    public function results(object $query, BaseContext $context): iterable
    {
        $rows = $query->get();
        $page = PeekPagination::for($context);
        if (! $page) {
            return $rows;
        }
        $page->more = $rows->count() > $page->limit;

        return $rows->take($page->limit);
    }

    public function count(object $query, BaseContext $context): ?int
    {
        // Pages say whether there's a next one; nothing shows a total, so nothing counts one.
        return null;
    }

    /**
     * @param Builder<Block> $query
     * @param array<string, mixed> $filters
     */
    private function listing(Builder $query, ?Pagination $pagination, Context $context, array $filters): Context
    {
        $actor = $context->getActor();
        $query->select('stance_blocks.*');

        if (isset($filters['post'])) {
            $post = Post::whereVisibleTo($actor)->find((int) $filters['post']);
            if (! $post || ! $actor->can('edit', $post)) {
                throw new PermissionDeniedException();
            }
            $query->where('stance_blocks.post_id', $post->id)->orderBy('stance_blocks.id');

            return $context->withQuery($query);
        }

        $tab = in_array($filters['tab'] ?? '', ['trending', 'new', 'closing', 'needs'], true) ? $filters['tab'] : 'trending';
        $now = Carbon::now();

        if ($tab === 'needs') {
            // The sidebar count's own query: votable, in SQL, so pages are never short.
            if (! $this->needs->apply($query, $actor)) {
                $query->whereRaw('1 = 0');
            }
        } else {
            $query->whereVisibleTo($actor);
        }
        if (in_array($filters['type'] ?? '', Block::TYPES, true)) {
            $query->where('stance_blocks.type', $filters['type']);
        }

        switch ($tab) {
            case 'new':
            case 'needs':
                $query->orderByDesc('stance_blocks.id');
                break;
            case 'closing':
                $query->whereNull('stance_blocks.closed_at')->where('stance_blocks.closes_at', '>', $now)->orderBy('stance_blocks.closes_at');
                break;
            default:
                // Recent bursts beat big old totals: votes in the last 48 hours, the last 6 counted three times.
                $scores = $query->getConnection()->table('stance_vote_log')
                    ->selectRaw('block_id, SUM(CASE WHEN created_at > ? THEN 3 ELSE 1 END) AS score', [$now->copy()->subHours(self::RECENT_HOURS)])
                    ->where('created_at', '>', $now->copy()->subHours(self::TRENDING_HOURS))
                    ->whereNotNull('choice')
                    ->groupBy('block_id');
                $query->joinSub($scores, 'stance_trend', 'stance_trend.block_id', '=', 'stance_blocks.id')
                    ->orderByDesc('stance_trend.score')
                    ->orderByDesc('stance_blocks.id');
        }

        if ($pagination instanceof PeekPagination) {
            $pagination->apply($query);
        }

        return $context->withQuery($query);
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        resolve(StanceWriter::class)->create($model, $this->attributes($context), $context->getActor());

        return $model;
    }

    /** @param Context $context */
    public function updating(object $model, BaseContext $context): ?object
    {
        resolve(StanceWriter::class)->update($model, $this->attributes($context), $context->getActor());

        return $model;
    }

    /** @param Context $context */
    public function delete(object $model, BaseContext $context): void
    {
        $actor = $context->getActor();
        if (! Find::isOwnDraft($model, $actor) && ! resolve(Presenter::class)->one($model, $actor)['canModerate']) {
            throw new PermissionDeniedException();
        }

        resolve(Cleanup::class)->delete(new Collection([$model]));
    }

    /**
     * The attributes as sent. The fields only declare what may be written; StanceWriter applies
     * them together, because each rule depends on the others (the type decides the labels, a
     * closed block keeps its date, a picture must be the member's own upload).
     *
     * @return array<string, mixed>
     */
    private function attributes(BaseContext $context): array
    {
        $attributes = (array) ($context->body()['data']['attributes'] ?? []);
        if (array_key_exists('blockType', $attributes)) {
            $attributes['type'] = $attributes['blockType'];
            unset($attributes['blockType']);
        }

        return $attributes;
    }
}
