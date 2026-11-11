<?php

namespace ErnestDefoe\Stances;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * Which block makes a discussion's stance badges, and what each member chose in it.
 *
 * The badge block is the one the author or a moderator picked; with the admin's rule on "auto",
 * otherwise the featured block, else the first sided block in the opening post. Only a public
 * (not anonymous), sided block that's switched on, showing, and in a post everyone can see
 * qualifies, so a badge never reveals anything the block itself doesn't.
 */
class Sides
{
    /** @var WeakMap<ServerRequestInterface, array<string, array<int, Block>>>|null */
    private static ?WeakMap $memo = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly ConnectionInterface $db,
    ) {
    }

    /**
     * Two queries, whatever the number of discussions; once per request for the same discussions
     * (the discussion page asks for its badge block and its posts' badges).
     *
     * @param list<int> $discussionIds
     * @return array<int, Block> keyed by discussion id
     */
    public function badgeBlocks(array $discussionIds, ?ServerRequestInterface $request = null): array
    {
        if ($request) {
            self::$memo ??= new WeakMap();
            $ids = array_values(array_unique($discussionIds));
            sort($ids);
            $key = implode(',', $ids);

            $memo = self::$memo[$request] ?? [];
            if (! array_key_exists($key, $memo)) {
                $memo[$key] = $this->lookup($discussionIds);
                self::$memo[$request] = $memo;
            }

            return $memo[$key];
        }

        return $this->lookup($discussionIds);
    }

    /**
     * @param list<int> $discussionIds
     * @return array<int, Block>
     */
    private function lookup(array $discussionIds): array
    {
        $rule = $this->settings->badgeRule();
        if ($rule === Settings::RULE_OFF || ! $discussionIds) {
            return [];
        }

        $discussions = Discussion::query()->whereIn('id', array_unique($discussionIds))
            ->get(['id', 'first_post_id', 'stance_featured_id', 'stance_badge_id']);

        $picked = $discussions->pluck('stance_badge_id')->filter();
        $featured = $rule === Settings::RULE_AUTO ? $discussions->pluck('stance_featured_id')->filter() : collect();
        $firstPosts = $rule === Settings::RULE_AUTO ? $discussions->pluck('first_post_id')->filter() : collect();

        if ($picked->isEmpty() && $featured->isEmpty() && $firstPosts->isEmpty()) {
            return [];
        }

        $blocks = Block::query()
            ->where(function ($q) use ($picked, $featured, $firstPosts) {
                $q->whereIn('stance_blocks.id', $picked->merge($featured)->unique()->values())
                    ->orWhereIn('stance_blocks.post_id', $firstPosts->values());
            })
            ->whereIn('type', Block::SIDED)
            ->where('anonymous', false)
            ->where('badges', true)
            ->whereNull('stance_blocks.hidden_at')
            ->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('posts')
                ->whereColumn('posts.id', 'stance_blocks.post_id')
                ->whereNull('posts.hidden_at')
                ->where('posts.is_private', false))
            ->orderBy('stance_blocks.id')
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($discussions as $discussion) {
            $own = fn ($id) => $id && ($b = $blocks->get($id)) && (int) $b->discussion_id === (int) $discussion->id ? $b : null;

            $block = $own($discussion->getAttribute('stance_badge_id'));
            if (! $block && $rule === Settings::RULE_AUTO) {
                $block = $own($discussion->getAttribute('stance_featured_id'))
                    ?? $blocks->first(fn (Block $b) => (int) $b->post_id === (int) $discussion->first_post_id);
            }
            if ($block) {
                $out[(int) $discussion->id] = $block;
            }
        }

        return $out;
    }

    /**
     * Every vote, change and removal by these members in these blocks, oldest first: one query.
     *
     * @param list<int> $blockIds
     * @param list<int> $userIds
     * @return Collection<int|string, Collection<int, \stdClass>> keyed "block:user"
     */
    public function history(array $blockIds, array $userIds): Collection
    {
        if (! $blockIds || ! $userIds) {
            return collect();
        }

        return $this->db->table('stance_vote_log')
            ->whereIn('block_id', array_values(array_unique($blockIds)))
            ->whereIn('user_id', array_values(array_unique($userIds)))
            ->orderBy('id')
            ->get(['block_id', 'user_id', 'choice', 'created_at'])
            ->groupBy(fn ($row) => $row->block_id.':'.$row->user_id);
    }

    /**
     * The member's side now, and the side they were on when they posted, if that was different.
     *
     * @param Collection<int, \stdClass>|null $rows
     * @return array{block: int, choice: int, label: string|null, was: string|null}|null
     */
    public function side(Block $block, ?Collection $rows, ?Carbon $postedAt): ?array
    {
        if (! $rows || $rows->isEmpty()) {
            return null;
        }

        $now = $rows->last()->choice;
        if ($now === null) {
            return null;
        }

        $was = null;
        if ($postedAt && $this->settings->flag(Settings::CHANGED_TAG)) {
            // Strictly before: times are to the second, and the author of a block can only vote once
            // its post exists, so a vote in the same second as the post came after it.
            $then = $rows->filter(fn ($row) => Carbon::parse($row->created_at)->lt($postedAt))->last();
            if ($then && $then->choice !== null && (int) $then->choice !== (int) $now) {
                $was = $block->label((int) $then->choice);
            }
        }

        return ['block' => $block->id, 'choice' => (int) $now, 'label' => $block->label((int) $now), 'was' => $was];
    }
}
