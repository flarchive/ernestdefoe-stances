<?php

namespace ErnestDefoe\Stances\Scope;

use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Blocks in posts the actor can see (core's post visibility covers the discussion, tags, private
 * and hidden posts), still showing in their post. Registered with Extend\ModelVisibility, so the
 * notifications list hides alerts about blocks the member can no longer see.
 */
class BlockVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        $query->whereNull('stance_blocks.hidden_at')
            ->whereNotNull('stance_blocks.post_id')
            ->whereIn('stance_blocks.post_id', Post::whereVisibleTo($actor)->select('posts.id'));
    }
}
