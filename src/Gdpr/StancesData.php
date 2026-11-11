<?php

namespace ErnestDefoe\Stances\Gdpr;

use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Vote;
use ErnestDefoe\Stances\Votes;
use Flarum\Gdpr\Data\Type;

/**
 * A member's export: their votes (with the block's question only where they can still see it) and
 * the blocks they added. Erasing removes their votes and vote history and recounts those blocks;
 * blocks they added stay (others voted in them) without an author.
 */
class StancesData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-stances.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-stances.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $votes = Vote::query()->where('user_id', $this->user->id)->orderBy('created_at')->get();
        $visible = Block::query()->whereVisibleTo($this->user)
            ->whereIn('stance_blocks.id', $votes->pluck('block_id')->all() ?: [0])->get()->keyBy('id');

        $rows = [
            'votes' => $votes->map(fn (Vote $v) => [
                'question' => $visible->get($v->block_id)?->question,
                'choice' => ($b = $visible->get($v->block_id)) ? $b->label($v->choice) : $v->choice,
                'voted_at' => $v->created_at?->toIso8601String(),
                'changed_at' => $v->updated_at?->toIso8601String(),
            ])->all(),
            'blocks_added' => Block::query()->where('user_id', $this->user->id)->whereNotNull('post_id')
                ->get(['question', 'type', 'created_at'])->toArray(),
        ];

        return [['stances/stances.json' => $this->encodeForExport($rows)]];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        $blockIds = Vote::query()->where('user_id', $this->user->id)->pluck('block_id');
        Vote::query()->where('user_id', $this->user->id)->delete();
        Block::query()->getConnection()->table('stance_vote_log')->where('user_id', $this->user->id)->delete();
        $votes = resolve(Votes::class);
        foreach (Block::query()->whereIn('id', $blockIds)->get() as $block) {
            $votes->recountLocked($block);
        }
        Block::query()->whereNull('post_id')->where('user_id', $this->user->id)->delete();
        Block::query()->where('user_id', $this->user->id)->update(['user_id' => null]);
    }
}
