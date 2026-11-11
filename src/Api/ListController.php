<?php

namespace ErnestDefoe\Stances\Api;

use Carbon\Carbon;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\NeedsVote;
use ErnestDefoe\Stances\Presenter;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/stances?filter[tab]=trending|new|closing|needs&filter[type]=…&page[offset]=N
 *     The Stances page: blocks in posts the viewer can see, 20 at a time.
 * GET /api/stances?filter[post]=N
 *     A post's blocks, hidden ones included, for whoever may edit it (the composer's list).
 * GET /api/stances/{id}
 */
class ListController extends Handler
{
    public const PAGE = 20;
    public const TRENDING_HOURS = 48;
    public const RECENT_HOURS = 6;

    public function __construct(
        private readonly Presenter $presenter,
        private readonly NeedsVote $needs,
    ) {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if (Arr::get($request->getQueryParams(), 'id')) {
            return new JsonResponse(['data' => $this->presenter->one(Find::visible($request, $actor), $actor)]);
        }

        $filter = (array) Arr::get($request->getQueryParams(), 'filter', []);

        if (isset($filter['post'])) {
            $post = Post::whereVisibleTo($actor)->find((int) $filter['post']);
            if (! $post || ! $actor->can('edit', $post)) {
                self::deny();
            }
            $blocks = Block::query()->where('post_id', $post->id)->orderBy('id')->get();

            return new JsonResponse(['data' => $this->presenter->many($blocks, $actor)]);
        }

        $tab = in_array($filter['tab'] ?? '', ['trending', 'new', 'closing', 'needs'], true) ? $filter['tab'] : 'trending';
        $offset = max(0, (int) Arr::get($request->getQueryParams(), 'page.offset', 0));

        $query = Block::query()->whereVisibleTo($actor)->select('stance_blocks.*');
        if (in_array($filter['type'] ?? '', Block::TYPES, true)) {
            $query->where('stance_blocks.type', $filter['type']);
        }

        $now = Carbon::now();
        $open = function (Builder $q) use ($now) {
            $q->whereNull('stance_blocks.closed_at')
                ->where(fn ($q) => $q->whereNull('stance_blocks.closes_at')->orWhere('stance_blocks.closes_at', '>', $now));
        };

        switch ($tab) {
            case 'new':
                $query->orderByDesc('stance_blocks.id');
                break;
            case 'closing':
                $query->whereNull('stance_blocks.closed_at')->where('stance_blocks.closes_at', '>', $now)->orderBy('stance_blocks.closes_at');
                break;
            case 'needs':
                // The sidebar count's own query: votable, in SQL, so pages are never short.
                $needs = $this->needs->query($actor);
                if (! $needs) {
                    return new JsonResponse(['data' => [], 'links' => []]);
                }
                $query = $needs->select('stance_blocks.*')->orderByDesc('stance_blocks.id');
                if (in_array($filter['type'] ?? '', Block::TYPES, true)) {
                    $query->where('stance_blocks.type', $filter['type']);
                }
                break;
            default:
                // Recent bursts beat big old totals: votes in the last 48 hours, the last 6 counted three times.
                $scores = Block::query()->getConnection()->table('stance_vote_log')
                    ->selectRaw('block_id, SUM(CASE WHEN created_at > ? THEN 3 ELSE 1 END) AS score', [$now->copy()->subHours(self::RECENT_HOURS)])
                    ->where('created_at', '>', $now->copy()->subHours(self::TRENDING_HOURS))
                    ->whereNotNull('choice')
                    ->groupBy('block_id');
                $query->joinSub($scores, 'stance_trend', 'stance_trend.block_id', '=', 'stance_blocks.id')
                    ->orderByDesc('stance_trend.score')
                    ->orderByDesc('stance_blocks.id');
        }

        /** @var Collection<int, Block> $blocks */
        $blocks = $query->skip($offset)->take(self::PAGE + 1)->get();
        $more = $blocks->count() > self::PAGE;
        $blocks = $blocks->take(self::PAGE);
        $blocks->loadMissing(['discussion', 'post:id,number', 'user']);

        $today = $blocks->isEmpty() ? collect() : Block::query()->getConnection()->table('stance_vote_log')
            ->whereIn('block_id', $blocks->pluck('id'))
            ->where('created_at', '>', $now->copy()->subDay())
            ->whereNotNull('choice')
            ->groupBy('block_id')
            ->selectRaw('block_id, COUNT(*) AS aggregate')
            ->pluck('aggregate', 'block_id');

        $data = [];
        foreach ($this->presenter->many($blocks, $actor) as $i => $row) {
            $block = $blocks[$i];
            $data[] = $row + [
                'discussion' => $block->discussion ? ['id' => $block->discussion->id, 'title' => $block->discussion->title, 'slug' => $block->discussion->slug] : null,
                'postNumber' => $block->post?->number,
                'author' => $block->user ? ['username' => $block->user->username, 'displayName' => $block->user->display_name] : null,
                'votesToday' => (int) ($today[$block->id] ?? 0),
            ];
        }

        return new JsonResponse(['data' => $data, 'links' => $more ? ['next' => $offset + self::PAGE] : []]);
    }
}
