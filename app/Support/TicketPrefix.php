<?php

namespace App\Support;

use App\Services\BridgeRoster;
use Illuminate\Support\Str;

/**
 * Ticket key prefix from a project name: initials of the words ("Blue Harbor" -> BH), first two letters of a
 * one-word name ("Northwind" -> NO). Uppercase A-Z, at most 8 chars; made unique by appending the next letter of the
 * name, then a digit. Used by the tickets migration (existing projects) and when a project is created.
 */
final class TicketPrefix
{
    public const MAX = 8;

    public const PATTERN = '/^[A-Z][A-Z0-9]{0,7}$/';

    /**
     * Fixed prefixes keyed by project id: the optional "ticket_prefixes" of the roster file (BridgeRoster, kept
     * outside the repo). Used by the tickets migration and whenever a prefix is assigned, so these never depend on
     * name order. Other projects keep the generated prefix.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        return BridgeRoster::load()['ticket_prefixes'];
    }

    /**
     * Prefix for a project: the map() entry when set (and free), else generated from the name. Prefixes reserved in
     * map() for other projects are never handed out by the generator.
     *
     * @param  iterable<string>  $taken  prefixes already in use
     */
    public static function forProject(string $id, string $name, iterable $taken = []): string
    {
        $taken = array_map('strtoupper', is_array($taken) ? $taken : iterator_to_array($taken));
        $map = self::map();
        $mapped = $map[$id] ?? null;
        if ($mapped !== null && ! in_array($mapped, $taken, true)) {
            return $mapped;
        }
        $reserved = array_values(array_diff_key($map, [$id => true]));

        return self::generate($name, [...$taken, ...$reserved]);
    }

    /** @param  iterable<string>  $taken  prefixes already in use */
    public static function generate(string $name, iterable $taken = []): string
    {
        $taken = array_flip(array_map('strtoupper', is_array($taken) ? $taken : iterator_to_array($taken)));
        $words = array_values(array_filter(preg_split('/[^A-Z]+/', strtoupper(Str::ascii($name))) ?: []));
        if ($words === []) {
            $words = ['P'];
        }

        if (count($words) === 1) {
            $base = substr($words[0], 0, 2);
            $extra = substr($words[0], 2);
        } else {
            $base = implode('', array_map(fn (string $w) => $w[0], array_slice($words, 0, 4)));
            $extra = substr(end($words), 1);
        }

        // Candidates: base, then base + the following letters of the name, then base + 2..99.
        $candidate = $base;
        for ($i = 0; isset($taken[$candidate]) && $i < strlen($extra) && strlen($candidate) < self::MAX; $i++) {
            $candidate .= $extra[$i];
        }
        for ($n = 2; isset($taken[$candidate]); $n++) {
            $candidate = substr($base, 0, self::MAX - strlen((string) $n)).$n;
        }

        return $candidate;
    }
}
