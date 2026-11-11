<?php

namespace ErnestDefoe\Stances\Api;

use Carbon\Carbon;
use ErnestDefoe\Stances\Block;
use ErnestDefoe\Stances\Refusal;

/** Reading and checking what the block dialog sends. Every refusal names the field. */
final class Input
{
    public const MAX_DAYS = 730;

    public static function type(mixed $value, array $enabled): string
    {
        if (! is_string($value) || ! in_array($value, $enabled, true)) {
            throw new Refusal('stances_bad_type');
        }

        return $value;
    }

    public static function question(mixed $value): string
    {
        $text = self::line($value);
        if (mb_strlen($text) < 3 || mb_strlen($text) > 200) {
            throw new Refusal('stances_bad_question');
        }

        return $text;
    }

    /** @return array{0: string, 1: string}|null */
    public static function labels(string $type, mixed $value): ?array
    {
        if ($type === Block::RATING) {
            return null;
        }

        $value = is_array($value) ? array_values($value) : [];
        // The dialog fills in the forum's words (Agree/Disagree, Yes/No); members may reword them ("For / Against").
        $labels = [self::line($value[0] ?? ''), self::line($value[1] ?? '')];

        foreach ($labels as $label) {
            if ($label === '' || mb_strlen($label) > 40) {
                throw new Refusal('stances_bad_labels');
            }
        }
        if (mb_strtolower($labels[0]) === mb_strtolower($labels[1])) {
            throw new Refusal('stances_bad_labels');
        }

        return $labels;
    }

    public static function results(mixed $value, string $default): string
    {
        return is_string($value) && in_array($value, Block::RESULTS, true) ? $value : $default;
    }

    public static function closesAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $at = is_string($value) ? Carbon::parse($value)->utc() : null;
        } catch (\Throwable) {
            $at = null;
        }

        if (! $at || $at->lt(Carbon::now()->addMinute()) || $at->gt(Carbon::now()->addDays(self::MAX_DAYS))) {
            throw new Refusal('stances_bad_close');
        }

        return $at;
    }

    public static function bool(mixed $value, bool $default): bool
    {
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function choice(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new Refusal('stances_bad_choice');
        }

        return (int) $value;
    }

    private static function line(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', is_string($value) ? $value : '') ?? '');
    }
}
