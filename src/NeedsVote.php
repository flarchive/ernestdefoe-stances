<?php

namespace ErnestDefoe\Stances;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "Needs your vote": open blocks from the last 60 days that the member can see and vote in and
 * hasn't voted on. One query, used by the sidebar count, the Needs tab and the discussion-list
 * icon, so the three always agree. The per-tag vote permission is applied in SQL (Tags scopes
 * discussions for any ability), never by dropping rows after paging.
 *
 * The sidebar count rides on every page load, so it's cached per member for a minute. The key
 * carries a forum-wide version that anything changing which blocks are open or visible bumps
 * (Listener\NeedsVoteChanged); a member's own vote forgets only their key.
 */
class NeedsVote
{
    public const DAYS = 60;
    public const TTL = 60;
    private const VERSION = 'stances-needs-version';

    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly Repository $cache,
    ) {
    }

    /** @return Builder<Block>|null null when the member can't vote anywhere */
    public function query(User $actor): ?Builder
    {
        $query = Block::query();

        return $this->apply($query, $actor) ? $query : null;
    }

    /**
     * Narrows a block query to the member's "needs your vote" blocks.
     *
     * @param Builder<Block> $query
     * @return bool false when the member can't vote anywhere (the query is left as it was)
     */
    public function apply(Builder $query, User $actor): bool
    {
        $tags = $this->extensions->isEnabled('flarum-tags');
        if ($actor->isGuest() || ! ($tags ? $actor->hasPermissionLike('discussion.'.Settings::VOTE) : $actor->hasPermission('discussion.'.Settings::VOTE))) {
            return false;
        }

        $now = Carbon::now();
        $query->whereVisibleTo($actor)
            ->whereNull('stance_blocks.closed_at')
            ->where(fn ($q) => $q->whereNull('stance_blocks.closes_at')->orWhere('stance_blocks.closes_at', '>', $now))
            ->where('stance_blocks.created_at', '>', $now->copy()->subDays(self::DAYS))
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('stance_votes')
                ->whereColumn('stance_votes.block_id', 'stance_blocks.id')
                ->where('stance_votes.user_id', $actor->id))
            // Hidden posts take no votes, even for moderators who can see them.
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('posts')
                ->whereColumn('posts.id', 'stance_blocks.post_id')
                ->whereNull('posts.hidden_at'));

        if ($tags) {
            $query->whereIn('stance_blocks.discussion_id', Discussion::query()->whereVisibleTo($actor, 'discussion.'.Settings::VOTE)->select('discussions.id'));
        }
        if ($this->extensions->isEnabled('flarum-lock')) {
            $query->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('discussions')
                ->whereColumn('discussions.id', 'stance_blocks.discussion_id')
                ->where('discussions.is_locked', true));
        }

        return true;
    }

    public function count(User $actor): int
    {
        if ($actor->isGuest()) {
            return 0;
        }

        return (int) $this->cache->remember($this->key($actor), self::TTL, fn () => $this->query($actor)?->count() ?? 0);
    }

    /** Which blocks are open or visible changed: every member's count is worked out again. */
    public function changed(): void
    {
        $this->cache->forever(self::VERSION, (int) $this->cache->get(self::VERSION, 0) + 1);
    }

    public function forget(User $actor): void
    {
        $this->cache->forget($this->key($actor));
    }

    private function key(User $actor): string
    {
        return 'stances-needs-'.(int) $this->cache->get(self::VERSION, 0).'-'.$actor->id;
    }
}
