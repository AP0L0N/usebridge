<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Models\Workstream;
use App\Services\Tickets\Actor;
use App\Services\Tickets\Workstreams;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Workstream writes from the Portfolio Finder and the board tabs (portfolio-workstreams README §3.2), as the signed-in
 * user (Actor::forUser, source ui). JSON only: the UI updates optimistically and rolls back on an error. Every add /
 * rename / delete is logged in workstream_events (Workstreams). Archived projects are read-only (409).
 */
class WorkstreamController extends Controller
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    /** POST /projects/{slug}/workstreams {name} → 201 {data: workstream}. */
    public function store(Request $request, string $slug): JsonResponse
    {
        $project = $this->project($request, $slug);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $ws = Workstreams::create($project, $data['name'], $this->actor($request), $request->user());

        return response()->json(['data' => Workstreams::present($ws)], 201);
    }

    /** PATCH /projects/{slug}/workstreams/{id} {name} → {data, message: "Renamed to …"}. */
    public function update(Request $request, string $slug, string $id): JsonResponse
    {
        $ws = $this->workstream($request, $slug, $id);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $ws = Workstreams::rename($ws, $data['name'], $this->actor($request), $request->user());

        return response()->json(['data' => Workstreams::present($ws), 'message' => "Renamed to \"{$ws->name}\". Agents will use the new name."]);
    }

    /**
     * DELETE /projects/{slug}/workstreams/{id} {target_id | new_name}: tickets (and the default flag) move first;
     * required when the workstream has tickets, is the default, or is the only one. → {data: {target, moved}}.
     */
    public function destroy(Request $request, string $slug, string $id): JsonResponse
    {
        $ws = $this->workstream($request, $slug, $id);
        $data = $request->validate([
            'target_id' => ['nullable', 'string', 'max:64'],
            'new_name' => ['nullable', 'string', 'max:120'],
        ]);
        $result = Workstreams::delete($ws, $data['target_id'] ?? null, $data['new_name'] ?? null, $this->actor($request), $request->user());

        return response()->json(['data' => [
            'target' => $result['target'] ? Workstreams::present($result['target']) : null,
            'moved' => $result['moved'],
        ]]);
    }

    /** POST /projects/{slug}/workstreams/{id}/move-tickets {target_id}: "Move all tickets to…". */
    public function moveTickets(Request $request, string $slug, string $id): JsonResponse
    {
        $ws = $this->workstream($request, $slug, $id);
        $data = $request->validate(['target_id' => ['required', 'string', 'max:64']]);
        $target = Workstream::query()->where('project_id', $ws->project_id)->find($data['target_id']);
        abort_if($target === null, 422, 'Choose another workstream of this project.');

        return response()->json(['data' => ['moved' => Workstreams::moveAllTickets($ws, $target, $this->actor($request))]]);
    }

    /** POST /projects/{slug}/workstreams/{id}/default: tickets without a workstream land here from now on. */
    public function makeDefault(Request $request, string $slug, string $id): JsonResponse
    {
        return response()->json(['data' => Workstreams::present(Workstreams::makeDefault($this->workstream($request, $slug, $id)))]);
    }

    /** POST /projects/{slug}/workstreams/{id}/reorder {direction: up|down}. */
    public function reorder(Request $request, string $slug, string $id): JsonResponse
    {
        $ws = $this->workstream($request, $slug, $id);
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        Workstreams::reorder($ws, $data['direction']);

        return response()->json(['data' => Workstreams::ordered($ws->project)->map(fn (Workstream $w) => Workstreams::present($w))->all()]);
    }

    private function project(Request $request, string $slug): Project
    {
        $user = $request->user();
        $workspace = $this->workspace->get();
        abort_unless($user instanceof User && $workspace !== null && $workspace->hasMember($user), 404);

        return Project::query()->where('slug', $slug)->firstOrFail();
    }

    private function workstream(Request $request, string $slug, string $id): Workstream
    {
        return Workstream::query()->where('project_id', $this->project($request, $slug)->id)->findOrFail($id);
    }

    private function actor(Request $request): Actor
    {
        return Actor::forUser($request->user());
    }
}
