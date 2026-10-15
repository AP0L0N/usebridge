<?php

namespace Tests\Unit;

use App\Services\ProjectStatus;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProjectStatusLevelTest extends TestCase
{
    #[Test]
    public function levels(): void
    {
        // r3: <3 days L1, 3-6 days L2, >6 days L3
        $this->assertSame([1, 1, 1, 2, 2, 3, 3], array_map([ProjectStatus::class, 'level'], [0, 1, 2, 3, 6, 7, 30]));
        $now = Carbon::parse('2026-10-12 12:00');
        $this->assertSame(0, ProjectStatus::ageDays($now->copy()->subHours(23), $now));
        $this->assertSame(1, ProjectStatus::ageDays($now->copy()->subHours(24), $now));
        $this->assertSame(0, ProjectStatus::ageDays($now->copy()->addHour(), $now));
    }
}
