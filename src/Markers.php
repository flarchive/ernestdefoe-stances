<?php

namespace ErnestDefoe\Stances;

/**
 * The `[stance=N]` markers in a post. The formatter stores each as `<STANCE stance="N">…</STANCE>`
 * in the post's XML, so they're read from there: a marker inside a code block is plain text and
 * never counts.
 */
final class Markers
{
    /** @return list<int> in the order they appear, each once */
    public static function ids(?string $xml): array
    {
        if (! $xml || ! str_contains($xml, '<STANCE')) {
            return [];
        }

        preg_match_all('/<STANCE\b[^>]*\bstance="(\d+)"/', $xml, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    public static function has(?string $xml): bool
    {
        return $xml !== null && str_contains($xml, '<STANCE');
    }
}
