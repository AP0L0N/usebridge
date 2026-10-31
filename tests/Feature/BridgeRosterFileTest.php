<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Project;
use App\Services\BridgeRoster;
use App\Support\TicketPrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\RosterFile;
use Tests\TestCase;

/** BridgeRoster reads BRIDGE_ROSTER_PATH (outside the repo); anything wrong with the file = empty roster, one warning. */
class BridgeRosterFileTest extends TestCase
{
    use RefreshDatabase;
    use RosterFile;

    private const ROSTER = [
        'agents' => ['agent-ivo' => ['Ivo Tamsin', 'PM'], 'agent-pell' => ['Pell Okafor', 'QA']],
        'pms' => ['proj-fernleaf' => 'agent-ivo'],
        'ticket_prefixes' => ['proj-fernleaf' => 'FX'],
    ];

    #[Test]
    public function a_valid_file_is_loaded_and_applied(): void
    {
        $this->useRoster(self::ROSTER);
        Project::create(['id' => 'proj-fernleaf', 'name' => 'Fernleaf', 'slug' => 'fernleaf']);

        $this->assertSame(self::ROSTER, BridgeRoster::load());
        $this->assertSame('FX', Project::find('proj-fernleaf')->ticket_prefix);
        $this->assertSame('FX', TicketPrefix::forProject('proj-fernleaf', 'Fernleaf'));
        $this->assertNotSame('FX', TicketPrefix::forProject('proj-other', 'Fx Labs', []));

        $r = BridgeRoster::apply();
        $this->assertSame(['agent-ivo', 'agent-pell'], $r['agents_created']);
        $this->assertSame('PM', Agent::find('agent-ivo')->role_label);
        $this->assertSame('agent-ivo', Project::find('proj-fernleaf')->owner_agent_id);
    }

    #[Test]
    public function no_path_means_an_empty_roster_without_a_warning(): void
    {
        Log::spy();
        config(['bridge.roster.path' => null]);

        $this->assertSame(['agents' => [], 'pms' => [], 'ticket_prefixes' => []], BridgeRoster::load());
        $this->artisan('bridge:set-pms')->assertSuccessful();
        Log::shouldNotHaveReceived('warning');
    }

    public static function badFiles(): array
    {
        return [
            'not json' => ['{"agents": '],
            'not an object' => ['"agents"'],
            'agent row not a pair' => ['{"agents": {"agent-x": ["Only name"]}}'],
            'agents a string' => ['{"agents": "agent-x"}'],
            'pm not a string' => ['{"pms": {"proj-x": 7}}'],
            'bad prefix' => ['{"ticket_prefixes": {"proj-x": "lower"}}'],
        ];
    }

    #[Test]
    #[DataProvider('badFiles')]
    public function an_invalid_file_changes_nothing_and_logs_one_warning_without_contents(string $contents): void
    {
        Log::spy();
        $this->useRoster($contents);
        Project::create(['id' => 'proj-x', 'name' => 'Xylo', 'slug' => 'xylo', 'owner_agent_id' => null]);
        $agents = Agent::count();

        $this->assertSame(['agents' => [], 'pms' => [], 'ticket_prefixes' => []], BridgeRoster::load());
        BridgeRoster::apply();
        $this->artisan('bridge:set-pms')->assertSuccessful();

        $this->assertSame($agents, Agent::count());
        $this->assertNull(Project::find('proj-x')->owner_agent_id);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => ! str_contains($message, 'agent-x') && ! str_contains($message, 'proj-x'));
    }

    #[Test]
    public function a_missing_file_changes_nothing_and_logs_one_warning(): void
    {
        Log::spy();
        config(['bridge.roster.path' => sys_get_temp_dir().'/bridge-roster-does-not-exist.json']);

        $this->assertSame([], BridgeRoster::load()['agents']);
        BridgeRoster::apply();
        Log::shouldHaveReceived('warning')->once();
    }
}
