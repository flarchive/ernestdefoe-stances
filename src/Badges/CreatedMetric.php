<?php

namespace ErnestDefoe\Stances\Badges;

use ErnestDefoe\Stances\Event\BlocksPosted;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;
use Illuminate\Database\ConnectionInterface;

/** FoF Badges metric: stance blocks a member added that are in a post. Only loaded when fof/badges registers its metrics. */
class CreatedMetric implements MetricInterface
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function getType(): string
    {
        return 'stances_created';
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-stances.admin.badges.created';
    }

    public function getEventTriggers(): array
    {
        return [BlocksPosted::class => fn (BlocksPosted $event) => $event->actor];
    }

    public function getValue(User $user, array $config = []): int
    {
        return $this->db->table('stance_blocks')->where('user_id', $user->id)->whereNotNull('post_id')->whereNull('hidden_at')->count();
    }

    public function isBoolean(): bool
    {
        return false;
    }

    public function getConfigFields(): array
    {
        return [];
    }

    public function getExtensionDependencies(): array
    {
        return ['ernestdefoe/stances'];
    }
}
