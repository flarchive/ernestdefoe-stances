<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Presenter;
use ErnestDefoe\Stances\Vote;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/stances/{id}/voters?choice=N&page[offset]=M
 *
 * Who chose what, 50 at a time. Never for an anonymous block, and only once the viewer may see
 * the results: a voters list would give away results that are still hidden.
 */
class VotersController extends Handler
{
    public const PAGE = 50;

    public function __construct(private readonly Presenter $presenter)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $block = Find::visible($request, $actor);
        $data = $this->presenter->one($block, $actor);

        if ($block->anonymous || ! $data['canViewVoters'] || ! $data['resultsVisible']) {
            self::deny();
        }

        $offset = max(0, (int) Arr::get($request->getQueryParams(), 'page.offset', 0));
        $query = Vote::query()->where('block_id', $block->id)->orderByDesc('updated_at')->orderByDesc('id');
        $choice = Arr::get($request->getQueryParams(), 'choice');
        if ($choice !== null && $choice !== '') {
            $query->where('choice', (int) $choice);
        }

        $votes = $query->skip($offset)->take(self::PAGE + 1)->get(['user_id', 'choice']);
        $more = $votes->count() > self::PAGE;
        $votes = $votes->take(self::PAGE);
        $users = User::query()->whereIn('id', $votes->pluck('user_id'))->get()->keyBy('id');

        $rows = [];
        foreach ($votes as $vote) {
            $user = $users->get($vote->user_id);
            if (! $user) {
                continue;
            }
            $rows[] = [
                'choice' => $vote->choice,
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'displayName' => $user->display_name,
                    'avatarUrl' => $user->avatar_url,
                    'slug' => $user->slug ?? $user->username,
                ],
            ];
        }

        return new JsonResponse(['data' => $rows, 'links' => $more ? ['next' => $offset + self::PAGE] : []]);
    }
}
