<?php

namespace ErnestDefoe\Stances\Listener;

use Carbon\Carbon;
use ErnestDefoe\Stances\Access;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Event\BlocksPosted;
use ErnestDefoe\Stances\Markers;
use ErnestDefoe\Stances\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * A post's blocks follow its `[stance=N]` markers.
 *
 * On save: a marker for one of the author's drafts attaches that draft to the post; a block whose
 * marker was taken out is hidden (its votes are kept); putting the marker back shows it again.
 * Checked before the post is written: the per-tag permission and the per-post limit, so a post
 * that breaks either is refused with a message instead of silently losing blocks.
 */
class AttachBlocks
{
    public function __construct(
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
        private readonly Dispatcher $events,
    ) {
    }

    public function handle(Saving $event): void
    {
        $post = $event->post;
        if (! $post instanceof CommentPost || ! $post->isDirty('content')) {
            return;
        }

        $ids = Markers::ids($post->getAttributes()['content'] ?? null);
        $existing = $post->exists ? Block::query()->where('post_id', $post->id)->get()->keyBy('id') : collect();

        if (! $ids && $existing->isEmpty()) {
            return;
        }

        // The author's own drafts named in the text. A marker for anything else (another post's
        // block, someone else's draft, a typo) is left as text the forum shows as nothing.
        $drafts = $ids ? Block::query()->whereIn('id', $ids)->whereNull('post_id')->where('user_id', $event->actor->id)->get()->keyBy('id') : collect();

        if ($drafts->isNotEmpty()) {
            $discussion = $post->discussion_id ? Discussion::query()->find($post->discussion_id) : null;
            if (! Access::can($event->actor, Settings::CREATE, $discussion)) {
                throw new ValidationException(['stances' => $this->translator->trans('ernestdefoe-stances.api.no_permission')]);
            }
        }

        $showing = array_values(array_filter($ids, fn ($id) => $existing->has($id) || $drafts->has($id)));
        if (count($showing) > $this->settings->maxPerPost()) {
            throw new ValidationException(['stances' => $this->translator->trans('ernestdefoe-stances.api.too_many', ['count' => $this->settings->maxPerPost()])]);
        }

        $actor = $event->actor;
        $post->afterSave(function (CommentPost $post) use ($existing, $drafts, $showing, $actor) {
            foreach ($existing as $block) {
                $show = in_array($block->id, $showing, true);
                if ($show === ($block->hidden_at === null)) {
                    continue;
                }
                $block->hidden_at = $show ? null : Carbon::now();
                $block->save();
            }

            foreach ($drafts as $block) {
                $block->post_id = $post->id;
                $block->discussion_id = $post->discussion_id;
                $block->hidden_at = null;
                $block->save();
            }

            if ($drafts->isNotEmpty()) {
                $this->events->dispatch(new BlocksPosted($actor));
            }
        });
    }
}
