<?php

namespace ErnestDefoe\Stances\Console;

use Carbon\Carbon;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Listener\FollowPosts;
use ErnestDefoe\Stances\Notification\ClosedBlueprint;
use ErnestDefoe\Stances\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;

/**
 * Every five minutes: blocks that closed (by date or by hand) since the last run. With the admin's
 * "voting closed" alert on, the voters who can still see the block are told; either way each block
 * is marked, so switching the alert on later never alerts about old blocks. Reopening clears the
 * mark, and the alert carries the close time, so a second close is a new alert.
 */
class TickCommand extends Command
{
    protected $signature = 'stances:tick';

    protected $description = 'Mark closed Stances blocks and, if switched on, tell their voters.';

    public function handle(Settings $settings, NotificationSyncer $notifications, FollowPosts $follow, Repository $cache): int
    {
        // Posts moved by something other than fof/merge-discussions (fof/split, a script): blocks
        // follow. A full pass, so once an hour; the merged event is the main path.
        if ($cache->add('stances-follow-posts', 1, 3600)) {
            $follow->resync();
        }

        $now = Carbon::now();
        $alert = $settings->flag(Settings::CLOSED_ALERT, false);

        Block::query()
            ->whereNull('closed_notified_at')
            ->whereNotNull('post_id')
            ->where(fn ($q) => $q->whereNotNull('closed_at')->orWhere('closes_at', '<=', $now))
            ->orderBy('id')
            ->chunkById(50, function ($blocks) use ($alert, $notifications, $now) {
                foreach ($blocks as $block) {
                    if ($alert && $block->hidden_at === null && $block->vote_count > 0) {
                        // One sync with every recipient: sync() takes back any earlier alert of the same
                        // kind it isn't given, so calling it per batch would leave only the last batch.
                        $notifications->sync(new ClosedBlueprint($block), $this->recipients($block));
                    }
                    $block->closed_notified_at = $now;
                    $block->save();
                }
            });

        return 0;
    }

    /**
     * Voters who can still see the block. When a guest can see it, every voter can; otherwise
     * (a private discussion, a restricted tag, a hidden post) each voter is checked.
     *
     * @return list<User>
     */
    private function recipients(Block $block): array
    {
        $voters = User::query()
            ->whereIn('id', fn ($q) => $q->select('user_id')->from('stance_votes')->where('block_id', $block->id))
            ->get();

        if (Block::query()->whereVisibleTo(new Guest())->whereKey($block->id)->exists()) {
            return $voters->all();
        }

        // Visibility follows group permissions, so one check per set of groups is enough, except in a
        // private discussion, where it's per member (and there are few).
        $private = (bool) Discussion::query()->whereKey($block->discussion_id)->value('is_private');
        $voters->load('groups');
        $seen = [];

        return $voters->filter(function (User $u) use ($block, $private, &$seen) {
            $key = $private ? 'u'.$u->id : $u->groups->pluck('id')->sort()->implode(',');

            return $seen[$key] ??= Block::query()->whereVisibleTo($u)->whereKey($block->id)->exists();
        })->values()->all();
    }
}
