<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Presenter;
use ErnestDefoe\Stances\Refusal;
use ErnestDefoe\Stances\Votes;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST   /api/stances/{id}/vote   {choice}: vote, or change it where the block allows
 * DELETE /api/stances/{id}/vote   take it back where the block allows
 */
class VoteController extends Handler
{
    public const PER_MINUTE = 60;

    public function __construct(
        private readonly Votes $votes,
        private readonly Presenter $presenter,
        private readonly Repository $cache,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $block = Find::visible($request, $actor);

        if (! $this->presenter->one($block, $actor)['canVote']) {
            // Say why when it's the post or the discussion, not the member.
            if ($block->post?->hidden_at !== null) {
                throw new Refusal('stances_post_hidden');
            }
            if ($block->discussion?->getAttribute('is_locked')) {
                throw new Refusal('stances_discussion_locked');
            }
            self::deny();
        }

        $choice = $request->getMethod() === 'DELETE' ? null : Input::choice(self::body($request)['choice'] ?? null);
        if ($request->getMethod() !== 'DELETE' && $choice === null) {
            self::deny();
        }

        // Every vote and change is a history row: 60 a minute is far past anyone clicking. A fixed
        // window (add() never refreshes the expiry), counting only votes that changed something.
        $key = 'stances-votes-'.$actor->id;
        $this->cache->add($key, 0, 60);
        if ((int) $this->cache->get($key, 0) >= self::PER_MINUTE) {
            throw new Refusal('stances_too_many_votes', 429);
        }

        [$block, $changed] = $this->votes->vote($block, $actor, $choice);
        if ($changed) {
            $this->cache->increment($key);
        }

        return self::show($request, $block);
    }
}
