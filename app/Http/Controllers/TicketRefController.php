<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FindsTickets;
use App\Models\Label;
use App\Services\Tickets\BoardView;
use App\Services\Tickets\HumanActors;
use App\Services\Tickets\TicketView;
use App\Support\TicketRefs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ticket references in text (`[[ticket:<id>|<key>]]`, TicketRefs) for the web app: one batch lookup for the chips on a
 * page, and the payload of the ticket modal a chip opens on any page (no navigation).
 */
class TicketRefController extends Controller
{
    use FindsTickets;

    /** GET /ticket-refs?ids=tk-a,tk-b → {data: {id: {key, title, project_slug, archived}}}; unknown ids are left out. */
    public function index(Request $request): JsonResponse
    {
        $ids = $request->query('ids');
        $ids = is_array($ids) ? $ids : explode(',', is_string($ids) ? $ids : '');
        $ids = array_slice(array_values(array_filter(array_map('trim', array_filter($ids, 'is_string')))), 0, 200);

        return response()->json(['data' => (object) TicketRefs::resolve($ids)]);
    }

    /** GET /ticket-refs/{id}: the ticket detail plus what TicketDetail needs (project, columns, people, labels). */
    public function show(string $id): JsonResponse
    {
        $ticket = $this->ticket($id);
        $project = $ticket->project;
        $view = new TicketView;

        return response()->json(['data' => [
            'ticket' => ProjectController::ticketDetail($ticket, $view),
            'project' => (new BoardView($view))->project($project),
            'columns' => array_map(fn (array $c) => [...$c, 'count' => 0, 'tickets' => []], $project->boardColumns()),
            'people' => ProjectController::people(HumanActors::viewerId()),
            'labels' => Label::query()->orderBy('name')->get()->map->toView()->values()->all(),
        ]]);
    }
}
