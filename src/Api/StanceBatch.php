<?php

namespace ErnestDefoe\Stances\Api;

use Carbon\Carbon;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Presenter;
use Flarum\Api\Context;
use Illuminate\Database\Eloquent\Collection;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * StanceResource's fields for every block in a response at once, the way PostBatch does posts:
 * each getter queues its block and returns a closure, and the first closure Flarum resolves
 * presents them all (Presenter: a fixed number of queries) plus, on the Stances page, today's
 * votes (one query). State is keyed by the request.
 */
class StanceBatch
{
    /** @var WeakMap<ServerRequestInterface, array{blocks: array<int, Block>, rows: array<int, array<string, mixed>>|null}> */
    private static ?WeakMap $state = null;

    public function __construct(private readonly Presenter $presenter)
    {
        self::$state ??= new WeakMap();
    }

    public function get(Block $block, Context $context, string $key): \Closure
    {
        $request = $context->request;
        $state = self::$state[$request] ?? ['blocks' => [], 'rows' => null];
        $state['blocks'][$block->id] = $block;
        self::$state[$request] = $state;

        return function () use ($block, $context, $request, $key) {
            $state = self::$state[$request];
            if ($state['rows'] === null || ! isset($state['rows'][$block->id])) {
                $state['rows'] = ($state['rows'] ?? []) + $this->load($state['blocks'], $context);
                self::$state[$request] = $state;
            }

            return $state['rows'][$block->id][$key] ?? null;
        };
    }

    /**
     * @param array<int, Block> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function load(array $blocks, Context $context): array
    {
        $blocks = new Collection(array_values($blocks));
        $actor = $context->getActor();
        $rows = [];
        foreach ($this->presenter->many($blocks, $actor) as $row) {
            $rows[$row['id']] = $row;
        }

        // The Stances page: where each block is, who added it, and its votes in the last day.
        if ($context->listing(StanceResource::class)) {
            $blocks->loadMissing('user');
            $today = $blocks->isEmpty() ? collect() : Block::query()->getConnection()->table('stance_vote_log')
                ->whereIn('block_id', $blocks->pluck('id'))
                ->where('created_at', '>', Carbon::now()->subDay())
                ->whereNotNull('choice')
                ->groupBy('block_id')
                ->selectRaw('block_id, COUNT(*) AS aggregate')
                ->pluck('aggregate', 'block_id');

            foreach ($blocks as $block) {
                $rows[$block->id] += [
                    'discussion' => $block->discussion ? ['id' => $block->discussion->id, 'title' => $block->discussion->title, 'slug' => $block->discussion->slug] : null,
                    'postNumber' => $block->post?->number,
                    'author' => $block->user ? ['username' => $block->user->username, 'displayName' => $block->user->display_name] : null,
                    'votesToday' => (int) ($today[$block->id] ?? 0),
                ];
            }
        }

        return $rows;
    }
}
