<?php

namespace ErnestDefoe\Stances;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Per-tag permission checks. A check goes through the discussion's tags, so lists load every
 * discussion's tags in one query first (tags()) instead of once per block.
 */
final class Access
{
    public static function can(User $actor, string $ability, ?Discussion $discussion): bool
    {
        if (! $discussion) {
            return false;
        }

        return $actor->can($ability, $discussion);
    }

    /** May add blocks somewhere: any tag's create permission counts until the post says which. */
    public static function canCreateAnywhere(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermissionLike('discussion.'.Settings::CREATE);
    }

    /** The discussion's author (who may still add blocks) or a moderator. */
    public static function canSteer(User $actor, Discussion $discussion): bool
    {
        if (self::can($actor, Settings::MODERATE, $discussion)) {
            return true;
        }

        return ! $actor->isGuest() && (int) $discussion->user_id === (int) $actor->id && self::can($actor, Settings::CREATE, $discussion);
    }

    /** @param Collection<int, Discussion> $discussions */
    public static function tags(Collection $discussions): void
    {
        $first = $discussions->first();
        if ($first && $first->isRelation('tags')) {
            $discussions->loadMissing('tags');
        }
    }
}
