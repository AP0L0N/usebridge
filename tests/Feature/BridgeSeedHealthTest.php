<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\HealthService;
use App\Services\LoadService;
use Carbon\CarbonImmutable;
use Database\Seeders\BridgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BridgeSeedHealthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seed_health_and_load_match_spa_fixtures(): void
    {
        $this->seed(BridgeSeeder::class);
        $now = CarbonImmutable::parse('2026-09-09T12:00:00+02:00');
        $health = app(HealthService::class);
        $load = app(LoadService::class);

        $merkwell = Project::query()->where('slug', 'merkwell')->firstOrFail();
        $concierge = Project::query()->where('slug', 'concierge-avant')->firstOrFail();

        $mw = $health->enrichProject($merkwell, $now);
        $ca = $health->enrichProject($concierge, $now);

        $this->assertSame('green', $mw['health']);
        $this->assertSame('none', $mw['topBlocker']);
        $this->assertSame('yellow', $ca['health']);
        $this->assertSame(
            'Content calendar owner unclear for next sprint — blocks phase exit.',
            $ca['topBlocker'],
        );

        $rows = collect($load->buildAgentLoadRows($now))->keyBy(fn ($r) => $r['agent']['name']);
        $this->assertSame('overloaded', $rows['Dr. Janice Polito']['load']);
        $this->assertSame(4, $rows['Dr. Janice Polito']['dueSoonCount']);
        $this->assertSame('balanced', $rows['Anatoly Korenchkin']['load']);
        $this->assertSame('balanced', $rows['Tommy Suarez']['load']);
        $this->assertSame('free', $rows['Shodan']['load']);
        $this->assertSame('free', $rows['Captain William Diego']['load']);
    }
}
