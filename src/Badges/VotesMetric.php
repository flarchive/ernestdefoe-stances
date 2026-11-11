<?php

namespace ErnestDefoe\Stances\Badges;

use ErnestDefoe\Stances\Event\Voted;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;
use Illuminate\Database\ConnectionInterface;

/** FoF Badges metric: stance blocks a member has a vote in. Only loaded when fof/badges registers its metrics. */
class VotesMetric implements MetricInterface
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function getType(): string
    {
        return 'stances_voted';
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-stances.admin.badges.voted';
    }

    public function getEventTriggers(): array
    {
        return [Voted::class => fn (Voted $event) => $event->actor];
    }

    public function getValue(User $user, array $config = []): int
    {
        return $this->db->table('stance_votes')->where('user_id', $user->id)->count();
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
