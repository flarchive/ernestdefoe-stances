<?php

namespace ErnestDefoe\Stances;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "Needs your vote": open blocks from the last 60 days that the member can see and vote in and
 * hasn't voted on. One query, used by the sidebar count, the Needs tab and the discussion-list
 * icon, so the three always agree. The per-tag vote permission is applied in SQL (Tags scopes
 * discussions for any ability), never by dropping rows after paging.
 */
class NeedsVote
{
    public const DAYS = 60;

    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    /** @return Builder<Block>|null null when the member can't vote anywhere */
    public function query(User $actor): ?Builder
    {
        $tags = $this->extensions->isEnabled('flarum-tags');
        if ($actor->isGuest() || ! ($tags ? $actor->hasPermissionLike('discussion.'.Settings::VOTE) : $actor->hasPermission('discussion.'.Settings::VOTE))) {
            return null;
        }

        $now = Carbon::now();
        $query = Block::query()->whereVisibleTo($actor)
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

        return $query;
    }

    public function count(User $actor): int
    {
        return $this->query($actor)?->count() ?? 0;
    }
}
