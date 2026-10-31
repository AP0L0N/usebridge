<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Project;
use App\Models\RequiredInput;
use App\Models\StatusUpdate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL QA ONLY. Sample data shaped like Tommy's r1 mocks, with timestamps
 * relative to "now" so every age colour shows. Never run on production.
 */
class BridgeDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $ago = fn (float $days) => $now->copy()->subMinutes((int) round($days * 1440));

        DB::transaction(function () use ($ago) {
            RequiredInput::query()->delete();
            StatusUpdate::query()->delete();
            Project::query()->delete();
            Agent::query()->delete();

            foreach ([
                ['agent-enrique', 'Enrique Cortez'], ['agent-marie', 'Dr. Marie Delacroix'], ['agent-tommy', 'Tommy Suarez'],
                ['agent-william', 'William Diego'], ['agent-anatoly', 'Anatoly Korenchkin'], ['agent-xavier', 'Xavier'],
            ] as [$id, $name]) {
                Agent::create(['id' => $id, 'name' => $name, 'role_label' => 'Agent']);
            }

            $p = function (string $id, string $name, string $slug, array $extra = []) {
                return Project::create(['id' => $id, 'name' => $name, 'slug' => $slug, 'owner_agent_id' => 'agent-william', ...$extra]);
            };
            $u = function (string $id, string $proj, string $agent, string $body, Carbon $at) {
                return StatusUpdate::create(['id' => $id, 'project_id' => $proj, 'agent_id' => $agent, 'body' => $body, 'created_at' => $at]);
            };
            $in = function (string $id, string $proj, string $text, string $who, Carbon $since, ?Carbon $done = null, ?string $by = null) {
                return RequiredInput::create(['id' => $id, 'project_id' => $proj, 'text' => $text, 'waiting_on' => $who, 'since' => $since, 'resolved_at' => $done, 'resolved_by' => $by]);
            };

            $p('proj-acme', 'Acme Studio', 'acme-studio');
            $u('su-ae-1', 'proj-acme', 'agent-enrique', "Kick-off for round 4. Nothing blocking.", $ago(7.4));
            $u('su-ae-2', 'proj-acme', 'agent-tommy', "Round 4 final copy merged; footer phone number still a placeholder.\n\n**Waiting on:** Dana — AC footer phone number.", $ago(5.1));
            $u('su-ae-3', 'proj-acme', 'agent-enrique', "**Round 5 live** on the preview link.\n\n- Search window and favourites added\n- Help page for partner shops drafted\n\n**Waiting on:** Dana — 10 open questions (photos, marketplace claim).", $ago(4.3));
            $u('su-ae-4', 'proj-acme', 'agent-marie', "Round 6 strings delivered:\n\n1. Five idea names with sublines\n2. »Show more« plural forms\n3. Trip-row **Book** label", $ago(0.15));
            $u('su-ae-5', 'proj-acme', 'agent-enrique', "**Round 6 update shipped** to the client preview.\n\n- Region label now *Central Valley* everywhere\n- Harvest-week recount: **31** (was 35)\n- Changed files listed in [the r6 handoff](https://example.com/r6)\n\n**Waiting on:** Dana — new region photos; which central-region count to use.", $ago(0.05));
            $in('in-ae-1', 'proj-acme', 'Region photos (North Coast, Lake District, Highlands, River Delta)', 'Dana', $ago(4.3));
            $in('in-ae-2', 'proj-acme', 'Central-region count: 18 by city or 23 by region field?', 'Dana', $ago(2.2));
            $in('in-ae-3', 'proj-acme', 'Footer phone number', 'Dana', $ago(1.1));
            $in('in-ae-4', 'proj-acme', 'Final round 6 strings', 'Marie', $ago(3), $ago(0.15), 'Marie');
            $in('in-ae-5', 'proj-acme', 'Region label decision', 'Enrique', $ago(4), $ago(2.1), 'Enrique');
            $in('in-ae-6', 'proj-acme', 'Day-trip descriptions', 'Dana', $ago(5), $ago(3.1), 'Dana');

            $p('proj-kestrel', 'Lumen × Kestrel', 'lumen-kestrel');
            $u('su-cs-1', 'proj-kestrel', 'agent-xavier', 'Hotfix passed QA on preview — waiting on Watts to publish to live.', $ago(2.3));
            $in('in-cs-1', 'proj-kestrel', 'Publish hotfix to live', 'Watts', $ago(2.3));

            $p('proj-fernleaf', 'Fernleaf', 'fernleaf');
            $u('su-cp-1', 'proj-fernleaf', 'agent-xavier', 'Onboarding copy draft ready — waiting on Marie for the final pass.', $ago(1.2));
            $in('in-cp-1', 'proj-fernleaf', 'Final pass on onboarding copy', 'Marie', $ago(1.2));

            $p('proj-sundial', 'Sundial', 'sundial');
            $u('su-gs-1', 'proj-sundial', 'agent-anatoly', 'Teacher-email form live — waiting on Rami for the final test.', $ago(0.2));
            $in('in-gs-1', 'proj-sundial', 'Final test of the teacher contact form', 'Rami', $ago(0.2));

            $p('proj-blue-harbor', 'Blue Harbor', 'blue-harbor', ['reopened_at' => $ago(0.4)]);
            $u('su-ca-1', 'proj-blue-harbor', 'agent-william', 'Recipient list confirmed by **Tilen**. Archiving until next change.', $ago(3.2));
            $u('su-ca-2', 'proj-blue-harbor', 'agent-william', "**Reopened.** New weekly digest shipped.\n\n- Sent to the agreed list\n- No open questions", $ago(0.4));
            $in('in-ca-1', 'proj-blue-harbor', 'Digest recipient list', 'Tilen', $ago(5), $ago(3.2), 'Tilen');
            $in('in-ca-2', 'proj-blue-harbor', 'Tone sample for weekly digest', 'Marie', $ago(8), $ago(6.1), 'Marie');

            $p('proj-northwind', 'Northwind', 'northwind');
            $u('su-mw-1', 'proj-northwind', 'agent-anatoly', 'Inbox triage running smoothly; next review on Friday.', $ago(0.5));

            $p('proj-bridge', 'Bridge', 'bridge');
            $u('su-br-1', 'proj-bridge', 'agent-xavier', 'Redesign r1 in progress — two screens only.', $ago(0.02));

            $p('proj-cobalt', 'Cobalt Works', 'cobalt-works', ['archived_at' => $ago(30)]);
            $u('su-zm-1', 'proj-cobalt', 'agent-william', 'Launch page live. Project closed.', $ago(30));

            $p('proj-lumen0', 'Lumen Phase 0', 'lumen-phase-0', ['archived_at' => $ago(45)]);
            $u('su-c0-1', 'proj-lumen0', 'agent-william', 'Theme audit handed over to Phase 1.', $ago(45));
        });
    }
}
