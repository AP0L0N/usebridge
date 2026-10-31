<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FindsTickets;
use App\Http\Controllers\Controller;
use App\Services\Tickets\Actor;
use App\Services\Tickets\ProjectStatusService;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PM-set project status (SPEC §3). Every PATCH logs a project_status_events row, also when only the note changes. */
class ProjectStatusController extends Controller
{
    use FindsTickets;

    public function __construct(private readonly ProjectStatusService $status) {}

    /** GET /api/projects/{id}/status → current + history (newest first). */
    public function show(string $id): JsonResponse
    {
        $project = $this->projectById($id);
        $view = new TicketView;

        return response()->json(['data' => [
            'project_id' => $project->id,
            'current' => $this->status->current($project, $view),
            'history' => $this->status->history($project, $view),
        ]]);
    }

    /** PATCH /api/projects/{id}/status {status* smooth|waiting|archived, note ≤280, actor}. Omitting note clears it. */
    public function update(Request $request, string $id): JsonResponse
    {
        $project = $this->projectById($id);
        $actor = Actor::fromApi($request);
        $data = $request->validate(ProjectStatusService::rules());
        $denied = $this->status->canSet($project, $data['status'], $actor);
        abort_if($denied !== null, 403, (string) $denied);

        $event = $this->status->set($project, $data['status'], TicketService::clean($data['note'] ?? null), $actor);
        $view = new TicketView;

        return response()->json([
            'data' => [
                'project_id' => $project->id,
                'current' => $this->status->current($project->fresh(), $view),
                'event' => $this->status->event($event, $view),
            ],
            'warnings' => TicketService::warnings($project),
        ]);
    }
}
