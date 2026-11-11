<?php

namespace ErnestDefoe\Stances\Api;

use Carbon\Carbon;
use ErnestDefoe\Stances\Access;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Images;
use ErnestDefoe\Stances\Presenter;
use ErnestDefoe\Stances\Refusal;
use ErnestDefoe\Stances\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST  /api/stances        a draft block, from the composer's dialog; it joins the post when the
 *                           post is saved with its marker (Listener\AttachBlocks)
 * PATCH /api/stances/{id}   edit (wording only while nobody has voted, unless a moderator),
 *                           close or reopen, make anonymous (never undone once in a post)
 */
class SaveController extends Handler
{
    public const MAX_DRAFTS = 20;

    public function __construct(
        private readonly Settings $settings,
        private readonly Presenter $presenter,
        private readonly Images $images,
        private readonly Repository $cache,
    ) {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $body = self::body($request);

        if ($request->getMethod() === 'POST') {
            if (! Access::canCreateAnywhere($actor)) {
                self::deny();
            }
            if (isset($body['discussionId'])) {
                $discussion = Discussion::whereVisibleTo($actor)->find((int) $body['discussionId']);
                if (! Access::can($actor, Settings::CREATE, $discussion)) {
                    self::deny();
                }
            }
            if (Block::query()->whereNull('post_id')->where('user_id', $actor->id)->count() >= self::MAX_DRAFTS) {
                throw new Refusal('stances_too_many_drafts');
            }

            $block = new Block();
            $block->user_id = $actor->id;
            $this->fill($block, $body, true, $actor);
            $block->counts = array_fill(0, $block->maxChoice() - $block->minChoice() + 1, 0);
            $block->save();

            return new JsonResponse(['data' => $this->presenter->one($block, $actor)], 201);
        }

        $block = Find::visible($request, $actor);
        $data = $this->presenter->one($block, $actor);
        $draft = Find::isOwnDraft($block, $actor);

        if (array_key_exists('closed', $body)) {
            if (! $data['canClose']) {
                self::deny();
            }
            $closed = Input::bool($body['closed'], false);
            $block->closed_at = $closed ? ($block->closed_at ?? Carbon::now()) : null;
            if (! $closed) {
                // Reopened: it may close again, and then voters hear about it again.
                $block->closed_notified_at = null;
            }
            if (! $closed && $block->closes_at?->isPast()) {
                $block->closes_at = null;
            }
        }

        if (array_key_exists('anonymous', $body)) {
            $want = Input::bool($body['anonymous'], false);
            // Anonymous may be switched on later, never off: voters were promised nobody would see them.
            if ($want !== $block->anonymous) {
                if (! $data['canClose'] || (! $want && ! $draft)) {
                    throw new Refusal('stances_anonymous_final');
                }
                $block->anonymous = $want;
                if ($want) {
                    $block->badges = false;
                }
            }
        }

        if (array_key_exists('question', $body)) {
            if (! $draft && ! $data['canEdit']) {
                self::deny();
            }
            $this->fill($block, $body, false, $actor);
        }

        $block->counts ??= array_fill(0, $block->maxChoice() - $block->minChoice() + 1, 0);
        $block->save();

        return new JsonResponse(['data' => $this->presenter->one($block, $actor)]);
    }

    /**
     * The dialog sends the close date back with every edit. An unchanged one is kept as it is (it
     * may be in the past by now), and a closed block keeps its date: reopening is its own action.
     * Only a new date is checked; a new date means voters hear about that close too.
     */
    private function closesAt(Block $block, mixed $value, bool $new): ?Carbon
    {
        if (! $new) {
            if ($block->isClosed()) {
                return $block->closes_at;
            }
            try {
                $given = is_string($value) && $value !== '' ? Carbon::parse($value)->utc() : null;
            } catch (\Throwable) {
                $given = null;
            }
            $same = ($given === null && $block->closes_at === null)
                || ($given && $block->closes_at && abs($given->getTimestamp() - $block->closes_at->getTimestamp()) < 60);
            if ($same) {
                return $block->closes_at;
            }
        }

        $closesAt = Input::closesAt($value);
        if ($closesAt) {
            $block->closed_notified_at = null;
        }

        return $closesAt;
    }

    /** @param array<string, mixed> $body */
    private function fill(Block $block, array $body, bool $new, User $actor): void
    {
        $type = $new || $block->vote_count === 0
            ? Input::type($body['type'] ?? $block->type, $this->settings->types())
            : $block->type;

        if ($block->type !== $type && $block->vote_count === 0) {
            $block->counts = null;
        }
        $block->type = $type;
        $block->question = Input::question($body['question'] ?? null);
        $block->labels = Input::labels($type, $body['labels'] ?? null);
        $block->results = Input::results($body['results'] ?? null, $new ? $this->settings->results() : $block->results);
        $block->allow_change = Input::bool($body['allowChange'] ?? null, $new ? $this->settings->flag(Settings::ALLOW_CHANGE) : $block->allow_change);
        $block->allow_remove = $block->allow_change
            && Input::bool($body['allowRemove'] ?? null, $new ? $this->settings->flag(Settings::ALLOW_REMOVE) : $block->allow_remove);
        if ($new) {
            $block->anonymous = Input::bool($body['anonymous'] ?? null, false);
        }
        $block->badges = $block->isSided() && ! $block->anonymous
            && Input::bool($body['badges'] ?? null, $new ? $this->settings->flag(Settings::BADGES) : $block->badges);
        $block->closes_at = $this->closesAt($block, $body['closesAt'] ?? null, $new);

        $old = $block->images ?? [];
        $images = null;
        if ($type === Block::PICK) {
            $given = is_array($body['images'] ?? null) ? array_values($body['images']) : [];
            $images = [];
            foreach ([0, 1] as $i) {
                $path = $given[$i] ?? null;
                // Only a picture this block already had, or a fresh upload to our folder.
                $images[$i] = $this->images->owns($path) && (in_array($path, $old, true) || $this->mayClaim($path, $actor)) ? $path : null;
            }
        }
        $block->images = $images;

        $dropped = array_values(array_diff(array_filter($old), array_filter($images ?? [])));
        if ($dropped) {
            $gone = new Block();
            $gone->images = $dropped;
            $this->images->forget([$gone]);
        }
    }

    /** A picture this member uploaded, that no block uses yet: nobody can take over someone else's. */
    private function mayClaim(string $path, User $actor): bool
    {
        if ((int) $this->cache->get(Images::ownerKey($path)) !== (int) $actor->id) {
            return false;
        }

        // The random part of the name is [a-z0-9] only, so it's safe in LIKE however the JSON escapes the slash.
        return ! Block::query()->where('images', 'like', '%'.basename($path, '.webp').'%')->exists();
    }
}
