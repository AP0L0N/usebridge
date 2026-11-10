<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentInstructionEdit;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\Agents\AgentInstructions;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The daily agent-profile sync (docs/agent-instructions-sync.md). Every route: a token of the workspace OWNER (403 for
 * anyone else, like AgentController::store); reads need boards:read, writes agents:write (EnsureTokenAbility). An edit
 * of another workspace answers 404. State changes only from pending (409 otherwise).
 */
class AgentInstructionController extends Controller
{
    private const HASH = 'regex:/^[0-9a-f]{64}$/';

    public function __construct(private readonly AgentInstructions $instructions) {}

    /** POST /api/agent-profiles {profiles: [{agent_id, name, title?, description}]}. Extra fields are ignored. */
    public function storeProfiles(Request $request): JsonResponse
    {
        $this->ensureOwner($request);
        $data = $request->validate([
            'profiles' => ['required', 'array', 'min:1', 'max:200'],
            'profiles.*.agent_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/', 'distinct'],
            'profiles.*.name' => ['required', 'string', 'max:255'],
            'profiles.*.title' => ['nullable', 'string', 'max:255'],
            'profiles.*.description' => ['present', 'nullable', 'string', 'max:20000'],
        ]);

        return response()->json(['data' => $this->instructions->ingest(array_values($data['profiles']))]);
    }

    /**
     * POST /api/agents/sync: the roster file as-is (docs/agents-sync.md) {generated_at?, source?, sections?, agents: [{agent_id,
     * full_name, section, section_order, description?}]}. A full snapshot: listed agents missing from it are unlisted.
     * Unknown fields are ignored; any invalid field answers 422 and nothing is stored.
     */
    public function syncRoster(Request $request): JsonResponse
    {
        $this->ensureOwner($request);
        $data = $request->validate([
            'generated_at' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sections' => ['sometimes', 'nullable', 'array', 'max:200'],
            'sections.*' => ['string', 'max:64'],
            'agents' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'agents.*' => ['required', 'array'],
            'agents.*.agent_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/', 'distinct'],
            'agents.*.full_name' => ['required', 'string', 'max:255'],
            'agents.*.section' => ['required', 'string', 'max:64'],
            'agents.*.section_order' => ['required', 'integer:strict', 'between:0,99'],
            'agents.*.description' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        $agents = array_map(fn (array $a): array => [
            'agent_id' => $a['agent_id'],
            'full_name' => $a['full_name'],
            'section' => $a['section'],
            'section_order' => $a['section_order'],
            'description' => $a['description'] ?? null,
        ], $data['agents']);

        return response()->json(['data' => $this->instructions->syncRoster($agents)]);
    }

    /** GET /api/agent-instruction-edits ?status=pending (default) | applied | rejected | conflict | superseded. */
    public function index(Request $request): JsonResponse
    {
        $this->ensureOwner($request);
        $status = $request->query('status', AgentInstructionEdit::PENDING);
        if (! is_string($status) || ! in_array($status, AgentInstructionEdit::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'The status must be one of: '.implode(', ', AgentInstructionEdit::STATUSES).'.']);
        }

        return response()->json(['data' => $this->instructions->editsForApi($status)]);
    }

    /** POST /api/agent-instruction-edits/{id}/applied {description_hash}: the service checks it equals sha256(proposed_description). */
    public function applied(Request $request, int $id): JsonResponse
    {
        $edit = $this->findEdit($request, $id);
        $data = $request->validate(['description_hash' => ['required', 'string', self::HASH]]);

        return $this->present($this->instructions->applied($edit, $data['description_hash']));
    }

    /** POST /api/agent-instruction-edits/{id}/rejected {reason?} */
    public function rejected(Request $request, int $id): JsonResponse
    {
        $edit = $this->findEdit($request, $id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->present($this->instructions->rejected($edit, $data['reason'] ?? null));
    }

    /** POST /api/agent-instruction-edits/{id}/conflict {current_description_hash, reason?}: 422 when it equals base_hash or proposed_hash. */
    public function conflict(Request $request, int $id): JsonResponse
    {
        $edit = $this->findEdit($request, $id);
        $data = $request->validate([
            'current_description_hash' => ['required', 'string', self::HASH],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->present($this->instructions->conflict($edit, $data['current_description_hash'], $data['reason'] ?? null), [
            'current_description_hash' => $data['current_description_hash'],
        ]);
    }

    private function ensureOwner(Request $request): void
    {
        $workspace = app(CurrentWorkspace::class)->get();
        $user = $request->user();
        abort_unless($user instanceof User && $workspace?->isOwnedBy($user), 403,
            'Only a token of the workspace owner can sync agent instructions.');
    }

    private function findEdit(Request $request, int $id): AgentInstructionEdit
    {
        $this->ensureOwner($request);
        $edit = AgentInstructionEdit::query()->findOrFail($id);
        abort_unless($edit->isPending(), 409, "This edit is {$edit->status}, not pending.");

        return $edit;
    }

    /** @param  array<string, mixed>  $extra */
    private function present(AgentInstructionEdit $edit, array $extra = []): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $edit->id,
            'agent_id' => $edit->agent_id,
            'revision' => $edit->revision,
            'status' => $edit->status,
            'status_reason' => $edit->status_reason,
            'proposed_hash' => $edit->proposedHash(),
            'applied_hash' => $edit->applied_hash,
            'applied_at' => $edit->applied_at?->toIso8601String(),
            'resolved_at' => $edit->resolved_at?->toIso8601String(),
            'profile_description_hash' => AgentProfile::query()->where('agent_id', $edit->agent_id)->value('description_hash'),
            ...$extra,
        ]]);
    }
}
