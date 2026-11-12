<?php

namespace ErnestDefoe\Stances\Listener;

use ErnestDefoe\Stances\Event\Voted;
use ErnestDefoe\Stances\NeedsVote;
use Flarum\User\Event\GroupsChanged;

/**
 * Keeps the cached "needs your vote" counts honest. Posts and discussions hidden, restored,
 * deleted, locked, unlocked or moved to other tags change which blocks members can vote in, so
 * every count is worked out again; a member's own vote, or their groups changing, only theirs.
 * Permission saves fire no event (core writes them in bulk): counts catch up within the minute.
 */
class NeedsVoteChanged
{
    public function __construct(private readonly NeedsVote $needs)
    {
    }

    public function handle(object $event): void
    {
        $this->needs->changed();
    }

    public function voted(Voted $event): void
    {
        $this->needs->forget($event->actor);
    }

    public function groupsChanged(GroupsChanged $event): void
    {
        $this->needs->forget($event->user);
    }
}
