<?php

namespace ErnestDefoe\Stances\Listener;

use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Images;
use ErnestDefoe\Stances\Vote;
use ErnestDefoe\Stances\Votes;
use Flarum\Discussion\Event\Deleting as DiscussionDeleting;
use Flarum\Post\Event\Deleting as PostDeleting;
use Flarum\Post\Post;
use Flarum\User\Event\Deleting as UserDeleting;

/**
 * Blocks go with their post or discussion (the foreign keys do this too, where the database
 * enforces them: SQLite may not), taking their pictures with them. A deleted member's votes go
 * with them, so the blocks they voted in are recounted.
 */
class Cleanup
{
    public function __construct(
        private readonly Images $images,
        private readonly Votes $votes,
    ) {
    }

    public function post(PostDeleting $event): void
    {
        $this->delete(Block::query()->where('post_id', $event->post->id)->get());
    }

    /** The blocks of the posts still in the discussion: fof/merge-discussions moves posts out before deleting it. */
    public function discussion(DiscussionDeleting $event): void
    {
        $this->delete(Block::query()->whereIn('post_id', Post::query()->where('discussion_id', $event->discussion->id)->select('id'))->get());
    }

    public function user(UserDeleting $event): void
    {
        $blockIds = Vote::query()->where('user_id', $event->user->id)->pluck('block_id');
        $event->user->afterDelete(function () use ($blockIds) {
            foreach (Block::query()->whereIn('id', $blockIds)->get() as $block) {
                $this->votes->recountLocked($block);
            }
        });
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, Block> $blocks */
    public function delete($blocks): void
    {
        if ($blocks->isEmpty()) {
            return;
        }
        $ids = $blocks->pluck('id');
        $this->images->forget($blocks);
        Vote::query()->whereIn('block_id', $ids)->delete();
        $db = Block::query()->getConnection();
        $db->table('stance_vote_log')->whereIn('block_id', $ids)->delete();
        $db->table('discussions')->whereIn('stance_featured_id', $ids)->update(['stance_featured_id' => null]);
        $db->table('discussions')->whereIn('stance_badge_id', $ids)->update(['stance_badge_id' => null]);
        Block::query()->whereIn('id', $ids)->delete();
    }
}
