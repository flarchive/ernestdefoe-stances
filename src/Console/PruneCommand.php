<?php

namespace ErnestDefoe\Stances\Console;

use Carbon\Carbon;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Images;
use ErnestDefoe\Stances\Listener\Cleanup;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory;

/**
 * Daily: drafts nobody posted (a dialog closed, a composer thrown away) after two days, and
 * pictures uploaded for a block that was never made after a day.
 */
class PruneCommand extends Command
{
    protected $signature = 'stances:prune';

    protected $description = 'Remove unposted Stances drafts and unused pictures.';

    public function handle(Cleanup $cleanup, Images $images, Factory $files): int
    {
        $drafts = Block::query()->whereNull('post_id')->where('created_at', '<', Carbon::now()->subDays(2))->get();
        $cleanup->delete($drafts);

        $disk = $files->disk('flarum-assets');
        $used = [];
        foreach (Block::query()->whereNotNull('images')->pluck('images') as $list) {
            foreach ((array) (is_string($list) ? json_decode($list, true) : $list) as $path) {
                if ($path) {
                    $used[$path] = true;
                }
            }
        }

        $removed = 0;
        $dayAgo = Carbon::now()->subDay()->getTimestamp();
        foreach ($disk->files('stances') as $path) {
            if (! isset($used[$path]) && $images->owns($path) && $disk->lastModified($path) < $dayAgo) {
                $disk->delete($path);
                $removed++;
            }
        }

        $this->info("Removed {$drafts->count()} drafts and {$removed} unused pictures.");

        return 0;
    }
}
