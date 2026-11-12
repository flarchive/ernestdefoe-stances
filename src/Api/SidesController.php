<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Presenter;
use ErnestDefoe\Stances\Refusal;
use ErnestDefoe\Stances\Sides;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/stances/{id}/sides?choice=N
 *
 * "Show replies from": the ids of the posts in this discussion the viewer can see whose authors
 * are on that side now, with the opening post first. The forum points the post stream at these
 * ids, so a long discussion pages through one side instead of hiding what's already loaded.
 * Only for the discussion's badge block, and only once the viewer may see its results.
 */
class SidesController extends Handler
{
    public function __construct(
        private readonly Presenter $presenter,
        private readonly Sides $sides,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $block = Find::visible($request, $actor);
        $discussion = $block->discussion_id ? Discussion::query()->find($block->discussion_id) : null;
        $badge = $discussion ? ($this->sides->badgeBlocks([(int) $discussion->id], $request)[(int) $discussion->id] ?? null) : null;

        if (! $discussion || ! $badge || $badge->id !== $block->id || ! $this->presenter->one($block, $actor)['resultsVisible']) {
            self::deny();
        }

        $choice = Input::choice(Arr::get($request->getQueryParams(), 'choice'));
        if ($choice === null || $choice < $block->minChoice() || $choice > $block->maxChoice()) {
            throw new Refusal('stances_bad_side');
        }

        $ids = Post::whereVisibleTo($actor)
            ->where('posts.discussion_id', $discussion->id)
            ->where(fn ($q) => $q->where('posts.id', $discussion->first_post_id)
                ->orWhere(fn ($q) => $q->where('posts.type', 'comment')
                    ->whereIn('posts.user_id', fn (QueryBuilder $q) => $q->select('user_id')->from('stance_votes')
                        ->where('block_id', $block->id)->where('choice', $choice))))
            ->orderBy('posts.number')
            ->pluck('posts.id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return new JsonResponse(['data' => $ids]);
    }
}
