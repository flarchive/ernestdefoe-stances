<?php

namespace ErnestDefoe\Stances;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Setting keys and their readings. 🚨 extend.php uses string literals, never these constants:
 * reading a class constant there autoloads this class while a deploy is swapping composer's
 * autoload map, which fatals every request.
 */
final class Settings
{
    /** One switch per type: ernestdefoe-stances.type_agree, …_yesno, …_pick, …_rating. */
    public const TYPE_PREFIX = 'ernestdefoe-stances.type_';
    public const MAX_PER_POST = 'ernestdefoe-stances.max_per_post';
    public const RESULTS = 'ernestdefoe-stances.results';
    public const ALLOW_CHANGE = 'ernestdefoe-stances.allow_change';
    public const ALLOW_REMOVE = 'ernestdefoe-stances.allow_remove';
    public const BADGES = 'ernestdefoe-stances.badges';
    public const BADGE_RULE = 'ernestdefoe-stances.badge_rule';
    public const CHANGED_TAG = 'ernestdefoe-stances.changed_tag';
    public const NAV = 'ernestdefoe-stances.nav';
    public const LIST_ICON = 'ernestdefoe-stances.list_icon';
    public const CLOSED_ALERT = 'ernestdefoe-stances.closed_alert';

    /** The featured block, else the first sided block in the opening post. */
    public const RULE_AUTO = 'auto';
    /** Only a block the author or a moderator picks. */
    public const RULE_PICKED = 'picked';
    public const RULE_OFF = 'off';

    public const CREATE = 'stances.create';
    public const VOTE = 'stances.vote';
    public const VIEW_VOTERS = 'stances.viewVoters';
    public const MODERATE = 'stances.moderate';

    public const MILESTONES = [10, 50, 100, 500, 1000];

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    /** @return string[] */
    public function types(): array
    {
        return array_values(array_filter(Block::TYPES, fn ($type) => $this->flag(self::TYPE_PREFIX.$type)));
    }

    public function maxPerPost(): int
    {
        return max(1, min(10, (int) $this->settings->get(self::MAX_PER_POST, 3)));
    }

    public function results(): string
    {
        $value = (string) $this->settings->get(self::RESULTS, Block::SHOW_VOTED);

        return in_array($value, Block::RESULTS, true) ? $value : Block::SHOW_VOTED;
    }

    public function flag(string $key, bool $default = true): bool
    {
        $value = $this->settings->get($key);

        return $value === null || $value === '' ? $default : (bool) $value;
    }

    public function badgeRule(): string
    {
        $rule = (string) $this->settings->get(self::BADGE_RULE, self::RULE_AUTO);

        return in_array($rule, [self::RULE_AUTO, self::RULE_PICKED, self::RULE_OFF], true) ? $rule : self::RULE_AUTO;
    }
}
