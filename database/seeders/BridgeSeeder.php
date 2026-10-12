<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Project;
use App\Models\RequiredInput;
use App\Models\StatusUpdate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL / TEST ONLY. Wipes projects, updates and inputs. Never run on production.
 * r1 model: agents, projects (archived_at, reopened_at), status updates, required inputs.
 */
class BridgeSeeder extends Seeder
{
    public const AGENT_WILLIAM = 'agent-william';

    public const AGENT_ANATOLY = 'agent-anatoly';

    public const AGENT_JANICE = 'agent-janice';

    public const AGENT_TOMMY = 'agent-tommy';

    public const AGENT_SHODAN = 'agent-shodan';

    public const PROJ_MERKWELL = 'proj-merkwell';

    public const PROJ_CONCIERGE = 'proj-concierge-avant';

    public function run(): void
    {
        DB::transaction(function () {
            RequiredInput::query()->delete();
            StatusUpdate::query()->delete();
            Project::query()->delete();
            Agent::query()->delete();

            foreach ([
                ['id' => self::AGENT_JANICE, 'name' => 'Dr. Janice Polito', 'role_label' => 'Sales / content owner'],
                ['id' => self::AGENT_ANATOLY, 'name' => 'Anatoly Korenchkin', 'role_label' => 'Sales owner · Merkwell'],
                ['id' => self::AGENT_TOMMY, 'name' => 'Tommy Suarez', 'role_label' => 'Designer'],
                ['id' => self::AGENT_SHODAN, 'name' => 'Shodan', 'role_label' => 'Engineering'],
                ['id' => self::AGENT_WILLIAM, 'name' => 'Captain William Diego', 'role_label' => 'CEO'],
            ] as $row) {
                Agent::create($row);
            }

            Project::create(['id' => self::PROJ_MERKWELL, 'name' => 'Merkwell', 'slug' => 'merkwell', 'owner_agent_id' => self::AGENT_ANATOLY]);
            Project::create(['id' => self::PROJ_CONCIERGE, 'name' => 'Concierge Avant', 'slug' => 'concierge-avant', 'owner_agent_id' => self::AGENT_JANICE]);

            foreach ([
                ['id' => 'su-mw-1', 'project_id' => self::PROJ_MERKWELL, 'agent_id' => self::AGENT_ANATOLY, 'body' => 'Merkwell structure on track. No open blockers.', 'created_at' => '2026-09-09T11:00:00+02:00'],
                ['id' => 'su-ca-1', 'project_id' => self::PROJ_CONCIERGE, 'agent_id' => self::AGENT_JANICE, 'body' => 'Scope freeze note drafted; waiting Captain slot.', 'created_at' => '2026-09-08T15:00:00+02:00'],
            ] as $row) {
                StatusUpdate::create($row);
            }

            RequiredInput::create([
                'id' => 'in-ca-1', 'project_id' => self::PROJ_CONCIERGE, 'status_update_id' => 'su-ca-1',
                'text' => 'Approve or reject phase-2 scope freeze', 'waiting_on' => 'Tilen', 'since' => '2026-09-08T15:00:00+02:00',
            ]);
        });
    }
}
