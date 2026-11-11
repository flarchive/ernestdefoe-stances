<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Access;
use ErnestDefoe\Stances\Listener\Cleanup;
use ErnestDefoe\Stances\Presenter;
use ErnestDefoe\Stances\Refusal;
use ErnestDefoe\Stances\Votes;
use Flarum\Http\RequestUtil;
use Illuminate\Database\Eloquent\Collection;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST   /api/stances/{id}/reset     moderators: wipe the votes and their history
 * POST   /api/stances/{id}/feature   {on}: show it above the discussion (the starter or a moderator)
 * POST   /api/stances/{id}/badge     {on}: make the discussion's badges from it (the starter or a moderator)
 * DELETE /api/stances/{id}           the author's draft, or any block for a moderator
 */
class ModerateController extends Handler
{
    public function __construct(
        private readonly Votes $votes,
        private readonly Presenter $presenter,
        private readonly Cleanup $cleanup,
    ) {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $block = Find::visible($request, $actor);
        $data = $this->presenter->one($block, $actor);
        $path = $request->getUri()->getPath();

        if ($request->getMethod() === 'DELETE') {
            if (! Find::isOwnDraft($block, $actor) && ! $data['canModerate']) {
                self::deny();
            }
            $this->cleanup->delete(new Collection([$block]));

            return new EmptyResponse(204);
        }

        if (str_ends_with($path, '/reset')) {
            if (! $data['canModerate']) {
                self::deny();
            }
            $block = $this->votes->reset($block);
        } else {
            $discussion = $block->discussion;
            if (! $discussion || ! $block->post_id || ! Access::canSteer($actor, $discussion)) {
                self::deny();
            }
            $on = Input::bool(self::body($request)['on'] ?? null, true);
            $column = str_ends_with($path, '/badge') ? 'stance_badge_id' : 'stance_featured_id';

            if ($on && $column === 'stance_badge_id' && ! $block->makesBadges()) {
                throw new Refusal('stances_no_badges');
            }

            if ($on) {
                $discussion->setAttribute($column, $block->id);
            } elseif ((int) $discussion->getAttribute($column) === $block->id) {
                $discussion->setAttribute($column, null);
            }
            $discussion->save();
        }

        return new JsonResponse(['data' => $this->presenter->one($block, $actor)]);
    }
}
