<?php

namespace App\Services\Usage;

/**
 * Chart colour per agent, derived from the agent_id (never from rank), so an agent keeps its
 * colour across ranges (7 / 30 days), across days as ranks shift, and on its detail page.
 *
 * - PALETTE: 24 colours from two OKLCH rings (L 0.60 and L 0.44, 12 hues each), every one with
 *   >= 3:1 contrast on the white card (lowest is ~3.7:1, see tests/Unit/AgentColorsTest.php).
 *   Neighbouring slots differ in hue and lightness, so a probe step never lands on a look-alike.
 * - assign(): each agent's home slot is crc32(agent_id) mod 24. Agents are placed in order of
 *   first appearance in the stored days (then agent_id); a taken slot linear-probes to the next
 *   free one. Placing in first-seen order means a newly appearing agent can only take a free
 *   slot, it never moves an agent that already had one. (Back-filling an older day that brings
 *   an agent nobody has seen yet can still reshuffle later agents' collisions.)
 * - More agents than slots: probing then picks the next least-used slot, so colours repeat
 *   (evenly) instead of failing. Distinct colours are guaranteed only up to 24 agents ever stored.
 */
final class AgentColors
{
    /** @var list<string> */
    public const PALETTE = [
        '#C45B56', '#005C71', '#11957C', '#853244', '#A861AC', '#0C6240',
        '#7E8904', '#663D81', '#587CD0', '#5E5303', '#BC671F', '#10558F',
        '#0F919C', '#85371A', '#BD5A84', '#035F5C', '#44954A', '#7A3566',
        '#876DC8', '#3D5E06', '#A1790C', '#484890', '#0B8BC0', '#734701',
    ];

    /** Colour for an agent outside the assigned set (not expected: every stored agent is assigned). */
    public static function fallback(string $agentId): string
    {
        return self::PALETTE[self::home($agentId)];
    }

    public static function home(string $agentId): int
    {
        return crc32($agentId) % count(self::PALETTE);
    }

    /**
     * @param  list<string>  $agentIds  every stored agent, in placement order (first seen, then id)
     * @return array<string, string> agent_id => #RRGGBB
     */
    public static function assign(array $agentIds): array
    {
        $n = count(self::PALETTE);
        $used = array_fill(0, $n, 0);
        $out = [];
        foreach ($agentIds as $id) {
            if (isset($out[$id])) {
                continue;
            }
            $min = min($used);
            $slot = self::home($id);
            while ($used[$slot] !== $min) {
                $slot = ($slot + 1) % $n;
            }
            $used[$slot]++;
            $out[$id] = self::PALETTE[$slot];
        }

        return $out;
    }
}
