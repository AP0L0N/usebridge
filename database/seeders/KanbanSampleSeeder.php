<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Label;
use App\Models\Project;
use App\Models\ProjectReadState;
use App\Models\ProjectStatusEvent;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\TicketReadState;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCREENSHOTS ONLY, NEVER PRODUCTION.
 *
 * Mirrors the SAMPLE content of Tommy's Kanban mocks (/workspace/bridge-kanban, build.py) so the shots in
 * shots/kanban/ can be compared with the target PNGs. Every ticket, comment, count, PM pairing and date is invented.
 * It WIPES agents, projects and all ticket tables first, so it refuses to run unless APP_ENV is local or testing.
 *
 *   php artisan db:seed --class=KanbanSampleSeeder   (against a throwaway sqlite file)
 */
class KanbanSampleSeeder extends Seeder
{
    private Carbon $today;

    private int $ev = 0;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('KanbanSampleSeeder is for local screenshots only; it refuses to run in '.app()->environment().'.');
        }
        $this->today = Carbon::now('Europe/Ljubljana')->startOfDay();

        DB::transaction(function () {
            foreach (['ticket_read_states', 'project_read_states', 'ticket_events', 'ticket_comments', 'ticket_label', 'labels', 'tickets', 'project_status_events'] as $t) {
                DB::table($t)->delete();
            }
            Project::query()->delete();
            Agent::query()->delete();

            foreach ([
                ['agent-janice', 'Dr. Janice Polito', 'PM'], ['agent-erin', 'Erin', 'Developer'], ['agent-tommy', 'Tommy Suarez', 'Design'],
                ['agent-marie', 'Marie', 'Copy (SL)'], ['agent-shodan', 'Shodan', 'Engineering'], ['agent-enrique', 'Enrique', 'PM'],
                ['agent-alice', 'Alice', 'PM'], ['agent-anatoly', 'Anatoly Korenchkin', 'Sales'], ['agent-xerxes', 'Xerxes', 'PM'],
                ['agent-william', 'Captain William Diego', 'CEO'],
            ] as [$id, $name, $role]) {
                Agent::create(['id' => $id, 'name' => $name, 'role_label' => $role]);
            }

            $labels = [];
            foreach (['Decision' => 'navy', 'Review' => 'navy', 'Design' => 'rose', 'Frontend' => 'green', 'Backend' => 'grey', 'Copy' => 'amber',
                'Check-in' => 'violet', 'Cart' => 'violet', 'Home' => 'violet', 'QA' => 'amber', 'Idea' => 'grey'] as $name => $color) {
                $labels[$name] = Label::create(['id' => 'lb-'.strtolower($name), 'name' => $name, 'color' => $color])->id;
            }

            $this->concierge($labels);

            // Other projects: status as in the portfolio mock, tickets only as counts per column.
            $this->project('proj-shopify', 'Chipolo · Shopify migration', 'chipolo-shopify', 'CS', 'agent-shodan', 'waiting',
                'Translation batch 4 needs your OK on the native-reviewed strings.', 'agent-shodan', $this->at(-4, '18:02'), [4, 2, 2, 1, 1, 9], $this->at(-1, '16:30'));
            $this->project('proj-adria', 'AdriaEdition', 'adriaedition', 'AE', 'agent-enrique', 'smooth',
                'Region page live; mobile hero next. Nothing needed from you.', 'agent-enrique', $this->at(-1, '21:15'), [5, 3, 2, 0, 1, 12], $this->at(-1, '21:15'));
            $this->project('proj-chatpulse', 'ChatPulse', 'chatpulse', 'CP', 'agent-alice', 'smooth',
                'Tenant Figma port at screen 16 of 22.', 'agent-alice', $this->at(0, '06:50'), [2, 4, 3, 0, 0, 6], $this->at(0, '06:50'));
            $this->project('proj-merkwell', 'Merkwell', 'merkwell', 'ME', 'agent-anatoly', 'waiting',
                'Outreach list waits on your pricing call.', 'agent-anatoly', $this->at(-8, '10:05'), [1, 1, 0, 1, 0, 4], $this->at(-8, '10:05'));
            $this->project('proj-zeleni', 'Zeleni Mark', 'zeleni-mark', 'ZM', 'agent-xerxes', null, null, null, null, [0, 2, 1, 0, 0, 0], $this->at(-3, '11:00'));
            $arch = $this->project('proj-renewal', 'Chipolo renewal redesign', 'chipolo-renewal', 'CR', 'agent-shodan', 'archived',
                'Shelved by Tilen. Mocks archived.', 'agent-william', $this->at(-7, '09:30'), [0, 0, 0, 0, 0, 7], $this->at(-7, '09:30'));
            $arch->forceFill(['archived_at' => $this->at(-7, '09:30')])->save();
        });
    }

    private function at(int $days, string $time): Carbon
    {
        [$h, $m] = explode(':', $time);

        return $this->today->copy()->addDays($days)->setTime((int) $h, (int) $m)->setTimezone(config('app.timezone'));
    }

    private function project(string $id, string $name, string $slug, string $prefix, string $pm, ?string $status, ?string $note, ?string $by, ?Carbon $at, array $counts, Carbon $last): Project
    {
        $p = Project::create(['id' => $id, 'name' => $name, 'slug' => $slug, 'owner_agent_id' => $pm, 'ticket_prefix' => $prefix,
            'status' => $status, 'status_note' => $note, 'status_set_by' => $by, 'status_set_at' => $at, 'last_activity_at' => $last]);
        if ($status) {
            ProjectStatusEvent::create(['id' => 'pse-'.$slug, 'project_id' => $id, 'from_status' => null, 'to_status' => $status, 'note' => $note,
                'set_by' => $by, 'set_by_type' => 'agent', 'source' => 'api', 'created_at' => $at]);
            ProjectReadState::create(['user' => 'tilen', 'project_id' => $id, 'status_seen_at' => $last, 'last_seen_at' => $last]);
        }
        $n = 0;
        foreach (['backlog', 'todo', 'in_progress', 'waiting_tilen', 'review', 'done'] as $i => $col) {
            for ($k = 0; $k < $counts[$i]; $k++) {
                $n++;
                $this->ticket($p, "$prefix-$n", ['col' => $col, 't' => "Sample ticket $n", 'pos' => $k, 'a' => $pm, 'created' => $last->copy()->subDays(10),
                    'ask' => $col === 'waiting_tilen' ? 'Sample ask for Tilen.' : null, 'waiting' => $at, 'done' => $at ?? $last]);
            }
        }
        $p->forceFill(['ticket_seq' => $n])->save();

        return $p;
    }

    private function ticket(Project $p, string $key, array $o, array $labels = []): Ticket
    {
        $t = new Ticket;
        $t->forceFill([
            'id' => 'tk-'.strtolower(str_replace('-', '', $key)),
            'project_id' => $p->id,
            'key' => $key,
            'title' => $o['t'],
            'description' => $o['desc'] ?? null,
            'status' => $o['col'],
            'position' => $o['pos'] ?? 0,
            'assignee_type' => ($o['a'] ?? null) ? (($o['a'] === 'tilen') ? 'human' : 'agent') : null,
            'assignee_id' => $o['a'] ?? null,
            'priority' => $o['pri'] ?? 'p3',
            'due_on' => $o['due'] ?? null,
            'blocked_reason' => $o['blk'] ?? null,
            'ask' => $o['ask'] ?? null,
            'ask_context' => $o['ctx'] ?? null,
            'ask_options' => $o['opts'] ?? null,
            'waiting_since' => $o['col'] === 'waiting_tilen' ? ($o['waiting'] ?? $o['created']) : null,
            'tilen_input_at' => $o['input_at'] ?? null,
            'tilen_input_ack_at' => $o['ack_at'] ?? null,
            'created_by' => $p->owner_agent_id,
            'created_by_type' => 'agent',
            'done_at' => $o['col'] === 'done' ? ($o['done'] ?? $o['created']) : null,
            'created_at' => $o['created'],
            'updated_at' => $o['updated'] ?? $o['created'],
        ])->save();
        if ($labels) {
            $t->labels()->sync($labels);
        }

        return $t;
    }

    private function comment(Ticket $t, string $author, string $body, Carbon $at, array $extra = []): void
    {
        TicketComment::query()->forceCreate(['ticket_id' => $t->id, 'author_type' => $author === 'tilen' ? 'human' : 'agent', 'author_id' => $author,
            'body' => $body, 'created_at' => $at, 'updated_at' => $at, ...$extra]);
    }

    private function event(Ticket $t, string $type, ?array $from, ?array $to, string $actor, Carbon $at): void
    {
        TicketEvent::query()->forceCreate(['id' => ++$this->ev, 'ticket_id' => $t->id, 'project_id' => $t->project_id, 'type' => $type, 'from' => $from, 'to' => $to,
            'actor_id' => $actor, 'actor_type' => 'agent', 'source' => 'api', 'created_at' => $at]);
    }

    private function concierge(array $L): void
    {
        $p = Project::create(['id' => 'proj-concierge-avant', 'name' => 'Concierge Avant', 'slug' => 'concierge-avant', 'owner_agent_id' => 'agent-janice',
            'ticket_prefix' => 'CA', 'status' => 'waiting', 'status_note' => 'Two decisions waiting on you: link expiry default and the cart sign-off. Build is on track otherwise.',
            'status_set_by' => 'agent-janice', 'status_set_at' => $this->at(0, '07:40'), 'last_activity_at' => $this->at(0, '07:58')]);
        foreach ([['smooth', 'waiting', 'Need the cart scope call.', -7, '17:30'], ['waiting', 'smooth', 'Check-in build on track, nothing needed.', -3, '09:10'],
            ['smooth', 'waiting', $p->status_note, 0, '07:40']] as $i => [$from, $to, $note, $d, $tm]) {
            ProjectStatusEvent::create(['id' => "pse-ca-$i", 'project_id' => $p->id, 'from_status' => $i ? $from : null, 'to_status' => $to, 'note' => $note,
                'set_by' => 'agent-janice', 'set_by_type' => 'agent', 'source' => 'api', 'created_at' => $this->at($d, $tm)]);
        }
        // Tilen last opened the project yesterday, so the status-change toast shows once.
        ProjectReadState::create(['user' => 'tilen', 'project_id' => $p->id, 'status_seen_at' => $this->at(-1, '20:00'), 'last_seen_at' => $this->at(-1, '20:00')]);

        $old = $this->at(-6, '10:00');
        $rows = [
            ['CA-131', 'backlog', 'Late check-out request in the Home module', ['Home', 'Idea'], null],
            ['CA-128', 'backlog', 'Room service: allergen icons on menu items', ['Design'], 'agent-tommy', 'cm' => 1],
            ['CA-133', 'backlog', 'Reminder to guests 24 h before the link closes', ['Check-in', 'Idea'], null],
            ['CA-127', 'todo', 'Wire holder.you.* SL strings', ['Frontend', 'Copy'], 'agent-erin', 'blk' => 'Waiting on Marie’s SL copy'],
            ['CA-124', 'todo', 'Boutique cart: Done closes the sheet', ['Cart', 'Frontend'], 'agent-erin', 'pri' => 'p2'],
            ['CA-129', 'todo', 'Empty state: no upcoming stays', ['Design'], 'agent-tommy'],
            ['CA-118', 'in_progress', 'Single-guest share-link form', ['Check-in', 'Frontend'], 'agent-erin', 'pri' => 'p1', 'cm' => 4, 'due' => $this->today->copy()->addDay()->format('Y-m-d'), 'unread' => true],
            ['CA-121', 'in_progress', 'First-launch intro: 2 direction starts', ['Design'], 'agent-tommy', 'cm' => 2],
            ['CA-122', 'in_progress', 'Dynamic Home module API', ['Home', 'Backend'], 'agent-erin', 'cm' => 1],
            ['CA-119', 'waiting_tilen', 'Sign off the cart flow on staging', ['Review', 'Cart'], 'agent-janice', 'cm' => 4, 'ask' => 'Review staging and approve, or list fixes.', 'waiting' => $this->at(-4, '08:00'), 'input' => true],
            ['CA-116', 'review', 'Cart: items always listed (Hide items removed)', ['Cart', 'QA'], 'agent-tommy', 'cm' => 2],
            ['CA-117', 'review', 'Landing copy: single-guest intro', ['Copy'], 'agent-janice'],
            ['CA-110', 'done', 'Multi-guest link path /neu/group-check-in', ['Check-in'], 'agent-erin', 'done' => $this->at(-1, '15:00')],
            ['CA-112', 'done', 'Review screen: required confirm checkbox', ['Check-in'], 'agent-erin', 'cm' => 2, 'done' => $this->at(-2, '15:00')],
            ['CA-108', 'done', 'Placeholder guest Sam Rivera in all mocks', ['Design'], 'agent-tommy', 'done' => $this->at(-3, '15:00')],
        ];
        $pos = [];
        foreach ($rows as $r) {
            [$key, $col, $title, $lb, $a] = $r;
            $pos[$col] = ($pos[$col] ?? -1) + 1;
            $t = $this->ticket($p, $key, ['col' => $col, 't' => $title, 'a' => $a, 'pos' => $pos[$col], 'created' => $old, 'pri' => $r['pri'] ?? null,
                'blk' => $r['blk'] ?? null, 'due' => $r['due'] ?? null, 'ask' => $r['ask'] ?? null, 'waiting' => $r['waiting'] ?? null, 'done' => $r['done'] ?? null,
                'input_at' => ($r['input'] ?? false) ? $this->at(-1, '19:30') : null], array_map(fn ($n) => $L[$n], $lb));
            for ($i = 0; $i < ($r['cm'] ?? 0); $i++) {
                $last = $i === ($r['cm'] ?? 0) - 1;
                if (($r['input'] ?? false) && $last) {
                    $this->comment($t, 'tilen', 'Two fixes first: the Done button label and the empty-cart copy. Otherwise approved.', $this->at(-1, '19:30'), ['is_tilen_input' => true, 'input_choice' => null]);
                } else {
                    $this->comment($t, $a ?? 'agent-janice', 'Sample comment '.($i + 1).'.', $this->at(($r['unread'] ?? false) && $last ? 0 : -2, ($r['unread'] ?? false) && $last ? '07:20' : '10:00'));
                }
            }
            if ($r['unread'] ?? false) {
                // Tilen commented on it earlier and last opened it yesterday: Erin's reply this morning is unread.
                $this->comment($t, 'tilen', 'Can the form remember the last guest?', $this->at(-2, '12:00'));
                TicketReadState::create(['user' => 'tilen', 'ticket_id' => $t->id, 'last_seen_at' => $this->at(-1, '20:00')]);
            } else {
                TicketReadState::create(['user' => 'tilen', 'ticket_id' => $t->id, 'last_seen_at' => $this->at(0, '07:59')]);
            }
        }
        // Nine older Done tickets behind "Show 9 older".
        for ($i = 1; $i <= 9; $i++) {
            $pos['done']++;
            $this->ticket($p, 'CA-'.(90 + $i), ['col' => 'done', 't' => "Older sample ticket $i", 'a' => 'agent-erin', 'pos' => $pos['done'], 'created' => $this->at(-30, '10:00'), 'done' => $this->at(-10 - $i, '10:00')], [$L['Check-in']]);
        }

        // CA-115: the ticket in the detail mock (Waiting on Tilen, Your input box open).
        $t = $this->ticket($p, 'CA-115', [
            'col' => 'waiting_tilen', 't' => 'Group link: close at check-in, or after 48 h?', 'a' => 'agent-janice', 'pri' => 'p1', 'pos' => 0,
            'due' => $this->today->copy()->addDay()->format('Y-m-d'), 'created' => $this->at(-3, '16:20'), 'updated' => $this->at(-2, '18:25'),
            'waiting' => $this->at(-2, '18:10'),
            'ask' => 'Which default should the group check-in link use at launch?',
            'ctx' => 'Both options are a config switch, no extra build (Erin, Tue).',
            'opts' => ['48 h or check-in, whichever is first', 'Close at check-in only'],
            'desc' => "Guests open the link the main guest shares. It now closes after **48 h or at check-in**, whichever comes first. The flag `CHECKIN_LINK_CLOSE` switches it to close at the actual check-in only.\n\n- **A**: 48 h or check-in, whichever is first (current)\n- **B**: at check-in only (late guests can still register)\n\nSample ticket for the mock. Not a real decision record.",
        ], [$L['Decision'], $L['Check-in']]);
        $this->comment($t, 'agent-janice', 'Opened from the check-in spec. Today the link closes after 48 h **or** at check-in, whichever is first.', $this->at(-3, '16:20'));
        $this->comment($t, 'agent-janice', 'Tilen, one call from you: which default do we launch with? Details in the description.', $this->at(-2, '18:10'));
        $this->comment($t, 'agent-erin', 'Confirmed: it is a flag only (`CHECKIN_LINK_CLOSE`). No extra build either way.', $this->at(-2, '18:25'));
        $this->event($t, 'created', null, ['status' => 'todo'], 'agent-janice', $this->at(-3, '16:20'));
        $this->event($t, 'labelled', null, ['labels' => ['Decision', 'Check-in']], 'agent-janice', $this->at(-3, '16:21'));
        $this->event($t, 'due', null, ['due_on' => $t->due_on->format('Y-m-d')], 'agent-janice', $this->at(-3, '16:22'));
        $this->event($t, 'moved', ['status' => 'todo'], ['status' => 'in_progress'], 'agent-janice', $this->at(-3, '16:40'));
        $this->event($t, 'priority', ['priority' => 'p3'], ['priority' => 'p1'], 'agent-janice', $this->at(-2, '18:10'));
        $this->event($t, 'moved', ['status' => 'in_progress'], ['status' => 'waiting_tilen'], 'agent-janice', $this->at(-2, '18:10'));
        TicketReadState::create(['user' => 'tilen', 'ticket_id' => $t->id, 'last_seen_at' => $this->at(-2, '19:00')]);

        $p->forceFill(['ticket_seq' => 133])->save();
    }
}
