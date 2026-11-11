<?php

namespace ErnestDefoe\Stances\Event;

use ErnestDefoe\Stances\Block;
use Flarum\User\User;

/** A member voted, changed or took back a vote (after the transaction). */
class Voted
{
    public function __construct(public Block $block, public User $actor)
    {
    }
}
