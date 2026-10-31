<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\RequiredInput;
use App\Services\Tickets\HumanActors;
use App\Support\Workspaces\WorkspaceRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * r0 /api/ceo-queue-items kept working for agents (they post here in practice).
 * In r1 a project queue item IS a Required input waiting on the workspace owner (waiting_on = their first name):
 *   POST            -> open input (text = suggested_action), id "in-q-{ceoId}"
 *   POST {id}/dismiss -> input marked done (resolved_by = the owner's actor id)
 *   POST {id}/assign  -> input now waiting on that agent
 * Items without project_id have nowhere to show in r1 -> 422.
 */
class CeoQueueCompatController extends Controller
{
    private function find(string $id): RequiredInput
    {
        return RequiredInput::query()->whereIn('id', ['in-q-'.$id, 'in-r0-q-'.$id, $id])->firstOrFail();
    }

    private function ceoId(RequiredInput $i): string
    {
        return (string) preg_replace('/^in-(r0-)?q-/', '', $i->id);
    }

    private function present(RequiredInput $i): array
    {
        return [
            'id' => $this->ceoId($i),
            'input_id' => $i->id,
            'project_id' => $i->project_id,
            'reason' => $i->text,
            'suggested_action' => $i->text,
            'status' => $i->resolved_at ? 'dismissed' : ($i->waiting_on === HumanActors::ownerFirstName() ? 'open' : 'assigned'),
            'waiting_on' => $i->waiting_on,
            'surfaced_at' => $i->since->toDateString(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $q = RequiredInput::query()->where(fn ($w) => $w->where('id', 'like', 'in-q-%')->orWhere('id', 'like', 'in-r0-q-%'))->orderByDesc('since');
        $items = $q->get()->map(fn ($i) => $this->present($i));
        if ($request->filled('status')) {
            $items = $items->where('status', (string) $request->string('status'))->values();
        }

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:58'],
            'reason' => ['required', 'string'],
            'suggested_action' => ['required', 'string'],
            'project_id' => ['required', 'string', WorkspaceRule::exists('projects', 'id')],
            'chip_label' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:open,assigned,dismissed'],
            'surfaced_at' => ['nullable', 'date'],
            'assigned_agent_id' => ['nullable', 'string', WorkspaceRule::exists('agents', 'id')],
        ], ['project_id.required' => 'Bridge r1 shows queue items as Required inputs on a project; project_id is required.']);

        $ceoId = $data['id'] ?? 'ceo-'.Str::lower((string) Str::ulid());
        $agent = isset($data['assigned_agent_id']) ? (Agent::query()->whereKey($data['assigned_agent_id'])->value('name') ?? $data['assigned_agent_id']) : null;
        $now = Carbon::now();
        $input = RequiredInput::create([
            'id' => 'in-q-'.$ceoId,
            'project_id' => $data['project_id'],
            'text' => mb_substr($data['suggested_action'] ?: $data['reason'], 0, 2000),
            'waiting_on' => ($data['status'] ?? 'open') === 'assigned' && $agent ? $agent : HumanActors::ownerFirstName(),
            'since' => isset($data['surfaced_at']) ? Carbon::parse($data['surfaced_at']) : $now,
            'resolved_at' => ($data['status'] ?? null) === 'dismissed' ? $now : null,
            'resolved_by' => ($data['status'] ?? null) === 'dismissed' ? HumanActors::ownerId() : null,
        ]);

        return response()->json(['data' => $this->present($input)], 201);
    }

    public function dismiss(string $id): JsonResponse
    {
        $i = $this->find($id);
        $i->update(['resolved_at' => $i->resolved_at ?? Carbon::now(), 'resolved_by' => $i->resolved_by ?? HumanActors::ownerId()]);

        return response()->json(['data' => $this->present($i->fresh())]);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['agent_id' => ['required', 'string', WorkspaceRule::exists('agents', 'id')]]);
        $i = $this->find($id);
        $i->update(['waiting_on' => Agent::query()->whereKey($data['agent_id'])->value('name') ?? $data['agent_id'], 'resolved_at' => null, 'resolved_by' => null]);

        return response()->json(['data' => $this->present($i->fresh())]);
    }
}
