<?php

namespace Tests\Unit;

use App\Services\Usage\UsageReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UsageNamedAgentsTest extends TestCase
{
    /** @return list<array{id: string, tokens: int}> */
    private function agents(int ...$tokens): array
    {
        return array_map(fn ($t, $i) => ['id' => "ag$i", 'tokens' => $t], $tokens, array_keys($tokens));
    }

    #[Test]
    public function named_is_capped_at_twelve(): void
    {
        // 15 agents at exactly 4% each (+ 40% unattributed): all qualify, only the first 12 are named.
        $named = UsageReport::selectNamed($this->agents(...array_fill(0, 15, 40)), 1000);
        $this->assertCount(UsageReport::MAX_NAMED, $named);
        $this->assertSame('ag11', end($named)['id']);
    }

    #[Test]
    public function walk_stops_at_the_first_agent_under_three_percent(): void
    {
        $named = UsageReport::selectNamed($this->agents(500, 300, 30, 29, 29), 1000);
        $this->assertSame(['ag0', 'ag1', 'ag2'], array_column($named, 'id')); // 3.0% is in, 2.9% is out
    }

    #[Test]
    public function nobody_is_named_when_the_largest_is_under_three_percent(): void
    {
        $this->assertSame([], UsageReport::selectNamed($this->agents(29, 28, 27), 1000));
    }

    #[Test]
    public function zero_total_names_nobody(): void
    {
        $this->assertSame([], UsageReport::selectNamed($this->agents(0, 0), 0));
        $this->assertSame([], UsageReport::selectNamed([], 0));
    }
}
