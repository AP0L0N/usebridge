<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\CeoQueueItem;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Risk;
use App\Models\StatusUpdate;
use App\Models\WorkItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed from SPA src/data/seed.ts — Merkwell + Concierge.
 * No fake clients/revenue. Ops digest empty.
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
            CeoQueueItem::query()->delete();
            StatusUpdate::query()->delete();
            Risk::query()->delete();
            WorkItem::query()->delete();
            Milestone::query()->delete();
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

            Project::create([
                'id' => self::PROJ_MERKWELL,
                'name' => 'Merkwell',
                'slug' => 'merkwell',
                'owner_agent_id' => self::AGENT_ANATOLY,
                'goal' => 'Run Merkwell as a live LANTIS sales motion with clear ownership and visible health — labels and phase exits only; no invented pipeline numbers.',
                'phase' => 'Sales motion setup',
                'phase_done_looks_like' => 'Owner ritual dated, next deadline owned, and no open needs-decision on the portfolio card.',
                'next_deadline' => '2026-09-22',
                'needs_decision' => null,
            ]);

            Project::create([
                'id' => self::PROJ_CONCIERGE,
                'name' => 'Concierge Avant',
                'slug' => 'concierge-avant',
                'owner_agent_id' => self::AGENT_JANICE,
                'goal' => 'Stand up Concierge Avant as a coherent sales/content motion with clear phase exits — no invented pipeline numbers; track delivery readiness and owner clarity.',
                'phase' => 'Phase 2 · Content ops',
                'phase_done_looks_like' => 'Content calendar ownership assigned, phase-2 scope freeze signed by Captain, and next milestone dated with owner on every open work item.',
                'next_deadline' => '2026-09-12',
                'needs_decision' => 'Confirm phase-2 scope freeze before design polish',
            ]);

            foreach ([
                ['id' => 'ms-mw-1', 'project_id' => self::PROJ_MERKWELL, 'title' => 'Offer frame locked', 'due_at' => '2026-08-20', 'status' => 'done'],
                ['id' => 'ms-mw-2', 'project_id' => self::PROJ_MERKWELL, 'title' => 'Sales motion checklist', 'due_at' => '2026-09-22', 'status' => 'current'],
                ['id' => 'ms-mw-3', 'project_id' => self::PROJ_MERKWELL, 'title' => 'Owner update ritual', 'due_at' => '2026-10-06', 'status' => 'upcoming'],
                ['id' => 'ms-ca-1', 'project_id' => self::PROJ_CONCIERGE, 'title' => 'Brief locked', 'due_at' => '2026-08-28', 'status' => 'done'],
                ['id' => 'ms-ca-2', 'project_id' => self::PROJ_CONCIERGE, 'title' => 'Content ops skeleton', 'due_at' => '2026-09-12', 'status' => 'current'],
                ['id' => 'ms-ca-3', 'project_id' => self::PROJ_CONCIERGE, 'title' => 'Scope freeze checkpoint', 'due_at' => '2026-09-08', 'status' => 'upcoming'],
                ['id' => 'ms-ca-4', 'project_id' => self::PROJ_CONCIERGE, 'title' => 'Design polish gate', 'due_at' => '2026-09-26', 'status' => 'upcoming'],
            ] as $row) {
                Milestone::create($row);
            }

            foreach ([
                ['id' => 'wi-ca-1', 'project_id' => self::PROJ_CONCIERGE, 'title' => 'Assign content calendar owner', 'owner_agent_id' => self::AGENT_JANICE, 'due_at' => '2026-09-12', 'status' => 'blocked', 'done_looks_like' => 'Named owner + first two weeks dated'],
                ['id' => 'wi-ca-2', 'project_id' => self::PROJ_CONCIERGE, 'title' => 'Draft phase-2 scope freeze note', 'owner_agent_id' => self::AGENT_JANICE, 'due_at' => '2026-09-10', 'status' => 'active', 'done_looks_like' => 'One-pager Captain can approve/reject'],
                ['id' => 'wi-ca-3', 'project_id' => self::PROJ_CONCIERGE, 'title' => 'UI package handoff checklist', 'owner_agent_id' => self::AGENT_TOMMY, 'due_at' => '2026-09-09', 'status' => 'active', 'done_looks_like' => 'DESIGN.md + mocks reviewable by Captain'],
                ['id' => 'wi-ca-4', 'project_id' => self::PROJ_CONCIERGE, 'title' => 'Scaffold data model (parallel)', 'owner_agent_id' => self::AGENT_SHODAN, 'due_at' => null, 'status' => 'open', 'done_looks_like' => 'Seed Merkwell + Concierge; Lead Orbit stub'],
                ['id' => 'wi-mw-1', 'project_id' => self::PROJ_MERKWELL, 'title' => 'Clarify Merkwell phase done-looks-like', 'owner_agent_id' => self::AGENT_ANATOLY, 'due_at' => '2026-09-14', 'status' => 'active', 'done_looks_like' => 'Written phase definition — no invented commercial numbers'],
                ['id' => 'wi-mw-2', 'project_id' => self::PROJ_MERKWELL, 'title' => 'Sales motion checklist (internal)', 'owner_agent_id' => self::AGENT_ANATOLY, 'due_at' => '2026-09-16', 'status' => 'open', 'done_looks_like' => 'Internal checklist dated to the 2026-09-22 checkpoint'],
                ['id' => 'wi-mw-3', 'project_id' => self::PROJ_MERKWELL, 'title' => 'Support Merkwell sales narrative pass', 'owner_agent_id' => self::AGENT_JANICE, 'due_at' => '2026-09-11', 'status' => 'open', 'done_looks_like' => 'Notes handed to Anatoly — Janice remains contrib, not owner'],
                ['id' => 'wi-mw-4', 'project_id' => self::PROJ_MERKWELL, 'title' => 'Flag calendar vs Merkwell deadline conflict', 'owner_agent_id' => self::AGENT_JANICE, 'due_at' => '2026-09-13', 'status' => 'open', 'done_looks_like' => 'One-line conflict note in Bridge — no invented commitments'],
            ] as $row) {
                WorkItem::create($row);
            }

            Risk::create([
                'id' => 'risk-ca-1',
                'project_id' => self::PROJ_CONCIERGE,
                'text' => 'Content calendar owner unclear for next sprint — blocks phase exit.',
                'severity' => 'medium',
                'open' => true,
                'created_at' => '2026-09-08T16:00:00+02:00',
            ]);
            Risk::create([
                'id' => 'risk-ca-2',
                'project_id' => self::PROJ_CONCIERGE,
                'text' => 'Scope freeze checkpoint slipped past dated milestone.',
                'severity' => 'medium',
                'open' => true,
                'created_at' => '2026-09-08T09:00:00+02:00',
            ]);

            foreach ([
                ['id' => 'su-mw-1', 'project_id' => self::PROJ_MERKWELL, 'agent_id' => self::AGENT_ANATOLY, 'body' => 'Merkwell structure on track for the 2026-09-22 checkpoint. No open blockers.', 'created_at' => '2026-09-09T11:00:00+02:00'],
                ['id' => 'su-ca-1', 'project_id' => self::PROJ_CONCIERGE, 'agent_id' => self::AGENT_JANICE, 'body' => 'Scope freeze note drafted; waiting Captain slot. Calendar ownership still open.', 'created_at' => '2026-09-08T15:00:00+02:00'],
                ['id' => 'su-ca-2', 'project_id' => self::PROJ_CONCIERGE, 'agent_id' => self::AGENT_TOMMY, 'body' => 'Ink Command visual system + five module mocks in progress for handoff.', 'created_at' => '2026-09-09T10:00:00+02:00'],
                ['id' => 'su-ca-3', 'project_id' => self::PROJ_CONCIERGE, 'agent_id' => self::AGENT_SHODAN, 'body' => 'Data model scaffold can proceed in parallel; UI wait on Tommy package.', 'created_at' => '2026-09-07T16:00:00+02:00'],
            ] as $row) {
                StatusUpdate::create($row);
            }

            // ops_digest_items intentionally empty

            CeoQueueItem::create([
                'id' => 'ceo-1',
                'reason' => 'Concierge Avant has a non-empty needs-decision and a slipped scope-freeze milestone — portfolio health is yellow and phase exit is blocked without Captain call.',
                'suggested_action' => 'Approve or reject phase-2 scope freeze; confirm content calendar owner with Janice.',
                'project_id' => self::PROJ_CONCIERGE,
                'status' => 'open',
                'surfaced_at' => '2026-09-08',
            ]);
            CeoQueueItem::create([
                'id' => 'ceo-2',
                'reason' => 'Agent load shows Janice overloaded (4 items due in 7 days on Concierge, plus Merkwell contrib) — risk of silent stall.',
                'suggested_action' => 'Pull Janice off Merkwell contrib work or defer non-critical Concierge items; keep Anatoly as Merkwell owner.',
                'chip_label' => 'cross-project',
                'status' => 'open',
                'surfaced_at' => '2026-09-09',
            ]);
            CeoQueueItem::create([
                'id' => 'ceo-3',
                'reason' => 'Ops feed overdue follow-up on Concierge scope freeze with no Lead Orbit write path — needs human nudge outside CRM.',
                'suggested_action' => 'Send a direct nudge (outside Bridge) and mark follow-up resolved when acknowledged.',
                'project_id' => self::PROJ_CONCIERGE,
                'status' => 'open',
                'surfaced_at' => '2026-09-09',
            ]);
            CeoQueueItem::create([
                'id' => 'ceo-4',
                'reason' => 'Merkwell next deadline approaching — assigned for owner prep.',
                'suggested_action' => 'Anatoly: confirm milestone readiness before 2026-09-22.',
                'project_id' => self::PROJ_MERKWELL,
                'status' => 'assigned',
                'surfaced_at' => '2026-09-07',
                'assigned_agent_id' => self::AGENT_ANATOLY,
            ]);
        });
    }
}
