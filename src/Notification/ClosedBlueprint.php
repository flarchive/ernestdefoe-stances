<?php

namespace ErnestDefoe\Stances\Notification;

use ErnestDefoe\Stances\Block;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** Alert (admin setting, off by default): a block you voted in has closed, so its results are final. */
class ClosedBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Block $block)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->block;
    }

    /** The close time, so a block reopened and closed again is a new alert, not the old one restored. */
    public function getData(): mixed
    {
        return ['closed' => ($this->block->closed_at ?? $this->block->closes_at)?->toIso8601String()];
    }

    public static function getType(): string
    {
        return 'stanceClosed';
    }

    public static function getSubjectModel(): string
    {
        return Block::class;
    }
}
