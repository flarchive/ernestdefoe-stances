<?php

namespace ErnestDefoe\Stances\Notification;

use ErnestDefoe\Stances\Block;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * Alert: the author's block reached 10, 50, 100… votes. The subject is the block, which has an
 * API resource (Api\StanceResource): a subject without one 500s every notification list.
 */
class MilestoneBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Block $block, public int $count)
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

    public function getData(): mixed
    {
        return ['count' => $this->count];
    }

    public static function getType(): string
    {
        return 'stanceMilestone';
    }

    public static function getSubjectModel(): string
    {
        return Block::class;
    }
}
