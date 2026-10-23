<?php

namespace Tests\Unit;

use App\Services\Usage\AgentColors;
use App\Services\Usage\UsageReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AgentColorsTest extends TestCase
{
    /** WCAG 2 relative luminance of #RRGGBB. */
    private static function luminance(string $hex): float
    {
        $c = array_map(function ($i) use ($hex) {
            $v = hexdec(substr($hex, $i, 2)) / 255;

            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, [1, 3, 5]);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    #[Test]
    public function palette_has_24_distinct_colours_with_3_to_1_contrast_on_white(): void
    {
        $this->assertGreaterThanOrEqual(24, count(AgentColors::PALETTE));
        $this->assertSame(AgentColors::PALETTE, array_values(array_unique(AgentColors::PALETTE)));
        foreach (AgentColors::PALETTE as $hex) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $hex);
            $contrast = (1.0 + 0.05) / (self::luminance($hex) + 0.05); // #FFFFFF has luminance 1
            $this->assertGreaterThanOrEqual(3.0, $contrast, "$hex on #FFFFFF");
        }
    }

    #[Test]
    public function colours_are_distinct_up_to_the_palette_size_and_follow_the_hash(): void
    {
        $ids = array_map(fn ($i) => "agent-$i", range(1, count(AgentColors::PALETTE)));
        $colors = AgentColors::assign($ids);
        $this->assertCount(count($ids), array_unique($colors));
        // The first one placed always gets its home slot.
        $this->assertSame(AgentColors::PALETTE[AgentColors::home('agent-1')], $colors['agent-1']);
    }

    #[Test]
    public function a_later_agent_never_moves_an_earlier_one(): void
    {
        $ids = array_map(fn ($i) => "agent-$i", range(1, 19));
        $before = AgentColors::assign($ids);
        $after = AgentColors::assign([...$ids, 'zz-new', 'aa-new']);
        $this->assertSame($before, array_intersect_key($after, $before));
    }

    #[Test]
    public function more_agents_than_colours_reuse_slots_evenly(): void
    {
        $n = count(AgentColors::PALETTE);
        $colors = AgentColors::assign(array_map(fn ($i) => "a$i", range(1, 2 * $n)));
        $this->assertSame(array_fill_keys(AgentColors::PALETTE, 2), array_merge(array_fill_keys(AgentColors::PALETTE, 0), array_count_values($colors)));
    }

    #[Test]
    public function zero_usage_agents_are_left_out_of_the_series(): void
    {
        $ranked = [['id' => 'a', 'tokens' => 5], ['id' => 'b', 'tokens' => 1], ['id' => 'c', 'tokens' => 0]];
        $this->assertSame(['a', 'b'], array_column(UsageReport::withUsage($ranked), 'id'));
        $this->assertSame([], UsageReport::withUsage([['id' => 'x', 'tokens' => 0]]));
    }
}
