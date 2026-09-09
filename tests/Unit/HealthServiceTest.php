<?php

namespace Tests\Unit;

use App\Services\HealthService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthServiceTest extends TestCase
{
    private HealthService $health;

    private CarbonImmutable $signOff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->health = new HealthService;
        $this->signOff = CarbonImmutable::parse('2026-09-09T12:00:00+02:00');
    }

    #[Test]
    public function high_risk_is_red(): void
    {
        $this->assertSame('red', $this->health->computeHealth($this->base(['openRisks' => [['severity' => 'high']]])));
    }

    #[Test]
    public function past_deadline_is_red(): void
    {
        $this->assertSame('red', $this->health->computeHealth($this->base(['nextDeadline' => '2026-09-08'])));
    }

    #[Test]
    public function stale_with_open_work_is_red(): void
    {
        $this->assertSame('red', $this->health->computeHealth($this->base([
            'latestUpdateAt' => '2026-08-31T12:00:00+02:00',
            'hasOpenWork' => true,
        ])));
    }

    #[Test]
    public function deadline_within_7_days_is_yellow(): void
    {
        $this->assertSame('yellow', $this->health->computeHealth($this->base(['nextDeadline' => '2026-09-12'])));
    }

    #[Test]
    public function medium_risk_is_yellow(): void
    {
        $this->assertSame('yellow', $this->health->computeHealth($this->base(['openRisks' => [['severity' => 'medium']]])));
    }

    #[Test]
    public function needs_decision_is_yellow(): void
    {
        $this->assertSame('yellow', $this->health->computeHealth($this->base(['needsDecision' => 'Pick a path'])));
    }

    #[Test]
    public function healthy_baseline_is_green(): void
    {
        $this->assertSame('green', $this->health->computeHealth($this->base()));
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function base(array $over = []): array
    {
        return array_merge([
            'nextDeadline' => null,
            'needsDecision' => null,
            'openRisks' => [],
            'latestUpdateAt' => '2026-09-09T12:00:00+02:00',
            'hasOpenWork' => true,
            'now' => $this->signOff,
        ], $over);
    }
}
