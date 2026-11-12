<?php

namespace ErnestDefoe\Stances\Listener;

use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\NeedsVote;
use Illuminate\Database\ConnectionInterface;

/**
 * Keeps each block's discussion_id the same as its post's. Merging or splitting discussions moves
 * posts with plain updates (no model events, no timestamp to follow), so this runs on
 * fof/merge-discussions' merged event, and once an hour from stances:tick as a safety net for
 * anything else that moves posts (fof/split, a script). A featured or badge pointer left on a
 * discussion the block moved out of is cleared.
 */
class FollowPosts
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /** fof/merge-discussions: FoF\MergeDiscussions\Events\DiscussionWasMerged */
    public function handle(object $event): void
    {
        $this->resync();
    }

    public function resync(): int
    {
        $moved = Block::query()
            ->join('posts', 'posts.id', '=', 'stance_blocks.post_id')
            ->where(fn ($q) => $q->whereNull('stance_blocks.discussion_id')
                ->orWhereColumn('posts.discussion_id', '!=', 'stance_blocks.discussion_id'))
            ->get(['stance_blocks.id', 'stance_blocks.discussion_id as old_discussion', 'posts.discussion_id as new_discussion']);

        foreach ($moved->groupBy('new_discussion') as $discussionId => $rows) {
            Block::query()->whereIn('id', $rows->pluck('id'))->update(['discussion_id' => $discussionId]);
        }

        $this->clearStrayPointers();
        if ($moved->isNotEmpty()) {
            resolve(NeedsVote::class)->changed();
        }

        return $moved->count();
    }

    /** A featured or badge pointer whose block now lives in another discussion (a move, a swap). */
    public function clearStrayPointers(): void
    {
        foreach (['stance_featured_id', 'stance_badge_id'] as $column) {
            $stray = $this->db->table('discussions')
                ->join('stance_blocks', 'stance_blocks.id', '=', 'discussions.'.$column)
                ->where(fn ($q) => $q->whereNull('stance_blocks.discussion_id')
                    ->orWhereColumn('stance_blocks.discussion_id', '!=', 'discussions.id'))
                ->pluck('discussions.id');
            if ($stray->isNotEmpty()) {
                $this->db->table('discussions')->whereIn('id', $stray)->update([$column => null]);
            }
        }
    }
}
