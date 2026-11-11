<?php

namespace ErnestDefoe\Stances;

use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Eloquent\Collection;

/**
 * Blocks as the forum reads them. A page of blocks costs a fixed number of queries: their
 * discussions and tags (eager), and the viewer's own votes (one query).
 *
 * Counts and the rating average are left out on the server until the viewer may see them
 * (results "after voting" or "after it closes"); the vote total is always shown.
 */
class Presenter
{
    public function __construct(private readonly Factory $files)
    {
    }

    /**
     * @param Collection<int, Block> $blocks
     * @return array<int, array<string, mixed>>
     */
    public function many(Collection $blocks, User $actor): array
    {
        if ($blocks->isEmpty()) {
            return [];
        }

        $blocks->loadMissing(['discussion', 'post']);
        /** @var Collection<int, \Flarum\Discussion\Discussion> $discussions */
        $discussions = new Collection($blocks->pluck('discussion')->filter()->unique('id')->values()->all());
        Access::tags($discussions);

        $mine = $actor->isGuest() ? collect() : Vote::query()
            ->whereIn('block_id', $blocks->pluck('id'))
            ->where('user_id', $actor->id)
            ->pluck('choice', 'block_id');

        $can = [];
        $out = [];
        foreach ($blocks as $block) {
            $discussion = $block->discussion;
            $key = $discussion ? (int) $discussion->id : 0;
            $can[$key] ??= $discussion ? [
                'vote' => ! $actor->isGuest() && Access::can($actor, Settings::VOTE, $discussion),
                'moderate' => Access::can($actor, Settings::MODERATE, $discussion),
                'create' => Access::can($actor, Settings::CREATE, $discussion),
                'voters' => Access::can($actor, Settings::VIEW_VOTERS, $discussion),
                'steer' => Access::canSteer($actor, $discussion),
            ] : ['vote' => false, 'moderate' => false, 'create' => Access::canCreateAnywhere($actor), 'voters' => false, 'steer' => false];

            $out[] = $this->present($block, $actor, isset($mine[$block->id]) ? (int) $mine[$block->id] : null, $can[$key]);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function one(Block $block, User $actor): array
    {
        return $this->many(new Collection([$block]), $actor)[0];
    }

    /**
     * @param array<string, bool> $can
     * @return array<string, mixed>
     */
    private function present(Block $block, User $actor, ?int $mine, array $can): array
    {
        $closed = $block->isClosed();
        $owner = ! $actor->isGuest() && (int) $block->user_id === (int) $actor->id;
        $visible = $can['moderate']
            || $block->results === Block::SHOW_ALWAYS
            || $closed
            || ($block->results === Block::SHOW_VOTED && $mine !== null);

        $counts = $block->counts ?? array_fill(0, $block->maxChoice() - $block->minChoice() + 1, 0);
        $average = null;
        if ($block->type === Block::RATING && $block->vote_count > 0) {
            $sum = 0;
            foreach ($counts as $i => $n) {
                $sum += ($i + 1) * $n;
            }
            $average = round($sum / $block->vote_count, 1);
        }

        $disk = $this->files->disk('flarum-assets');

        return [
            'id' => $block->id,
            'type' => $block->type,
            'question' => $block->question,
            'labels' => $block->labels,
            'images' => $block->type === Block::PICK
                ? array_map(fn ($path) => $path ? $disk->url($path) : null, $block->images ?? [null, null])
                : null,
            'postId' => $block->post_id,
            'discussionId' => $block->discussion_id,
            'results' => $block->results,
            'allowChange' => $block->allow_change,
            'allowRemove' => $block->allow_remove,
            'anonymous' => $block->anonymous,
            'badges' => $block->badges,
            'closesAt' => $block->closes_at?->toIso8601String(),
            'closedAt' => $block->closed_at?->toIso8601String(),
            'closed' => $closed,
            'hidden' => $block->hidden_at !== null,
            'voteCount' => $block->vote_count,
            'resultsVisible' => $visible,
            'counts' => $visible ? array_values($counts) : null,
            'average' => $visible ? $average : null,
            'mine' => $mine,
            'canVote' => $can['vote'] && ! $closed && $block->hidden_at === null && $block->post_id !== null
                && $block->post?->hidden_at === null && ! $block->discussion?->getAttribute('is_locked'),
            'canEdit' => $can['moderate'] || ($owner && $can['create'] && $block->vote_count === 0),
            'canModerate' => $can['moderate'],
            'canClose' => $can['moderate'] || ($owner && $can['create']),
            'canViewVoters' => ! $block->anonymous && $can['voters'],
            'canSteer' => $can['steer'],
        ];
    }
}
