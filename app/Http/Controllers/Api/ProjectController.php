<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\RequiredInput;
use App\Services\BridgeRoster;
use App\Services\ProjectStatus;
use App\Services\Tickets\Actor;
use App\Support\TicketPrefix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    /** r0 fields that agents may still send; accepted and ignored (removed in r1). */
    private const LEGACY_FIELDS = ['goal', 'phase', 'phase_done_looks_like', 'next_deadline', 'needs_decision'];

    /** Every body key PATCH /api/projects/{id} understands; anything else is a 422 (no silent 200). */
    private const UPDATE_FIELDS = ['name', 'slug', 'owner_agent_id', 'pm_agent_id', 'archived', 'ticket_prefix', 'actor', 'author'];

    public function index(): JsonResponse
    {
        $projects = Project::query()->with(['statusUpdates', 'requiredInputs', 'questions'])->orderBy('name')->get()
            ->map(fn ($p) => $this->present($p));

        return response()->json(['data' => $projects]);
    }

    public function show(string $id): JsonResponse
    {
        $project = Project::query()->with(['statusUpdates', 'requiredInputs', 'questions'])->findOrFail($id);

        return response()->json(['data' => [
            ...$this->present($project),
            'required_inputs' => $project->requiredInputs->map(fn ($i) => ProjectStatus::inputView($i))->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64', 'unique:projects,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:projects,slug'],
            'owner_agent_id' => ['required', 'string', 'exists:agents,id'],
            'ticket_prefix' => ['nullable', 'string', 'regex:'.TicketPrefix::PATTERN, 'unique:projects,ticket_prefix'],
            ...array_fill_keys(self::LEGACY_FIELDS, ['nullable']),
        ]);
        $needsDecision = $data['needs_decision'] ?? null;
        $data = array_diff_key($data, array_flip(self::LEGACY_FIELDS));
        $data['id'] = $data['id'] ?? 'proj-'.Str::lower((string) Str::ulid());
        $project = Project::create($data);
        $this->syncNeedsDecision($project, $needsDecision, true);

        return response()->json(['data' => $this->present($project->load(['statusUpdates', 'requiredInputs', 'questions']))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        $unknown = array_values(array_diff(array_keys($request->all()), [...self::UPDATE_FIELDS, ...self::LEGACY_FIELDS]));
        if ($unknown) {
            throw ValidationException::withMessages(['fields' => 'Unknown field(s): '.implode(', ', $unknown)
                .'. Status goes through PATCH /api/projects/{id}/status; allowed here: '.implode(', ', array_diff(self::UPDATE_FIELDS, ['author'])).'.']);
        }
        if ($request->has('pm_agent_id') && $request->has('owner_agent_id') && $request->input('pm_agent_id') !== $request->input('owner_agent_id')) {
            throw ValidationException::withMessages(['pm_agent_id' => 'Send pm_agent_id or owner_agent_id (same field), not two different values.']);
        }
        $data = $request->validate([
            'pm_agent_id' => ['sometimes', 'string', 'exists:agents,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'unique:projects,slug,'.$project->id.',id'],
            'owner_agent_id' => ['sometimes', 'string', 'exists:agents,id'],
            'archived' => ['sometimes', 'boolean'],
            'ticket_prefix' => ['sometimes', 'string', 'regex:'.TicketPrefix::PATTERN, 'unique:projects,ticket_prefix,'.$project->id.',id'],
            ...array_fill_keys(self::LEGACY_FIELDS, ['nullable']),
        ]);
        // Keys are stored on the tickets, so the prefix may only change before the first ticket.
        if (isset($data['ticket_prefix']) && $data['ticket_prefix'] !== $project->ticket_prefix && $project->tickets()->exists()) {
            throw ValidationException::withMessages(['ticket_prefix' => 'The ticket prefix can only change while the project has no tickets.']);
        }
        if (array_key_exists('archived', $data)) {
            $project->archived_at = $data['archived'] ? ($project->archived_at ?? Carbon::now()) : null;
            if ($data['archived']) {
                $project->reopened_at = null;
            }
        }
        // The PM (pm_agent_id, stored as owner_agent_id) is Captain-only and logged in project_pm_events.
        $pm = $data['pm_agent_id'] ?? $data['owner_agent_id'] ?? null;
        $pmActor = null;
        if ($pm !== null && $pm !== $project->owner_agent_id) {
            $pmActor = Actor::fromApi($request);
            abort_unless($pmActor->isCaptain(), 403, 'Only Captain ('.Actor::CAPTAIN.') can change a project\'s PM.');
        }
        $project->fill(array_intersect_key($data, array_flip(['name', 'slug', 'ticket_prefix'])))->save();
        if ($pmActor !== null) {
            BridgeRoster::setPm($project, $pm, $pmActor->id, 'api');
        }
        $this->syncNeedsDecision($project, $data['needs_decision'] ?? null, $request->has('needs_decision'));

        return response()->json(['data' => $this->present($project->fresh()->load(['statusUpdates', 'requiredInputs', 'questions']))]);
    }

    public function archive(string $id): JsonResponse
    {
        return $this->update(new Request(['archived' => true]), $id);
    }

    public function unarchive(string $id): JsonResponse
    {
        return $this->update(new Request(['archived' => false]), $id);
    }

    public function destroy(string $id): JsonResponse
    {
        Project::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /**
     * r0 compat: needs_decision text -> one open input waiting on Tilen ("in-nd-{project}");
     * setting it to null/empty marks that input done.
     */
    private function syncNeedsDecision(Project $project, mixed $value, bool $present): void
    {
        if (! $present) {
            return;
        }
        $existing = RequiredInput::query()->whereIn('id', ['in-nd-'.$project->id, 'in-r0-nd-'.$project->id])->whereNull('resolved_at')->first();
        if (filled($value)) {
            if ($existing) {
                $existing->update(['text' => (string) $value]);
            } else {
                RequiredInput::query()->updateOrCreate(['id' => 'in-nd-'.$project->id], [
                    'project_id' => $project->id, 'text' => (string) $value, 'waiting_on' => 'Tilen',
                    'since' => Carbon::now(), 'resolved_at' => null, 'resolved_by' => null,
                ]);
            }
        } elseif ($existing) {
            $existing->update(['resolved_at' => Carbon::now(), 'resolved_by' => 'tilen']);
        }
    }

    private function present(Project $p): array
    {
        $s = ProjectStatus::summary($p);
        unset($s['searchText']);

        // `status` is the current PM-set status (PATCH /api/projects/{id}/status) once one is set; before that (and for
        // archived projects) it is the r1 status derived from open inputs/questions, kept as derived_status.
        $pmSet = $p->status !== null && $p->archived_at === null;

        return [
            ...$s,
            'status' => $pmSet ? $p->status : $s['status'],
            'level' => $pmSet ? $s['pmLevel'] : $s['level'],
            'ageDays' => $pmSet ? $s['pmAgeDays'] : $s['ageDays'],
            'derived_status' => $s['status'],
            'status_source' => $pmSet ? 'pm' : 'derived',
            'owner_agent_id' => $p->owner_agent_id,
            'pm_agent_id' => $p->owner_agent_id,
            'ticket_prefix' => $p->ticket_prefix,
        ];
    }
}
