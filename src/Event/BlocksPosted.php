<?php

namespace ErnestDefoe\Stances\Event;

use Flarum\User\User;

/** Drafts joined a post: their author added blocks to the forum. */
class BlocksPosted
{
    public function __construct(public User $actor)
    {
    }
}
