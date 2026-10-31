<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FindsTickets;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Tickets\Actor;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tickets API (Sanctum). Writes need an acting agent (see Actor::fromApi). No delete route: archive instead. */
class TicketController extends Controller
{
    use FindsTickets;

    public function __construct(private readonly TicketService $tickets) {}

    /** GET /api/tickets ?project_id=&status=&assignee=&label=&q=&needs_pm_pickup=1&unread_for_tilen=1&blocked=1&updated_since=&include_archived=1&limit= */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(TicketView::filterRules());
        foreach (['needs_pm_pickup', 'unread_for_tilen', 'blocked', 'include_archived'] as $k) {
            $f[$k] = self::flag($f[$k] ?? false);
        }
        $q = TicketView::filter(Ticket::query()->with(TicketView::with()), $f)->limit((int) ($f['limit'] ?? 100));
        $view = new TicketView;

        return response()->json(['data' => $q->get()->map(fn ($t) => $view->ticket($t))->all()]);
    }

    public function indexForProject(Request $request, string $id): JsonResponse
    {
        $request->merge(['project_id' => $this->projectById($id)->id]);

        return $this->index($request);
    }

    /** POST /api/tickets {project_id, …} */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['project_id' => ['required', 'string']]);

        return $this->create($request, $this->projectById($request->string('project_id')));
    }

    /** POST /api/projects/{id}/tickets */
    public function storeForProject(Request $request, string $id): JsonResponse
    {
        return $this->create($request, $this->projectById($id));
    }

    private function create(Request $request, Project $project): JsonResponse
    {
        $actor = Actor::fromApi($request);
        $data = $request->validate(TicketService::rules(true));
        $ticket = $this->tickets->create($project, $data, $actor);

        return $this->respond($ticket, 201);
    }

    /** GET /api/tickets/{ticket}: ticket + comments (oldest first) + newest 50 events. */
    public function show(string $ticket): JsonResponse
    {
        return response()->json(['data' => (new TicketView)->detail($this->ticket($ticket))]);
    }

    public function update(Request $request, string $ticket): JsonResponse
    {
        $t = $this->ticket($ticket);
        $actor = Actor::fromApi($request);
        $data = $request->validate(TicketService::rules(false));

        return $this->respond($this->tickets->update($t, $data, $actor));
    }

    public function move(Request $request, string $ticket): JsonResponse
    {
        $t = $this->ticket($ticket);
        $actor = Actor::fromApi($request);
        $data = $request->validate(TicketService::moveRules());

        return $this->respond($this->tickets->move($t, $data, $actor));
    }

    public function ackInput(Request $request, string $ticket): JsonResponse
    {
        $t = $this->ticket($ticket);

        return $this->respond($this->tickets->ackInput($t, Actor::fromApi($request)));
    }

    public function archive(Request $request, string $ticket): JsonResponse
    {
        $t = $this->ticket($ticket);

        return $this->respond($this->tickets->archive($t, Actor::fromApi($request)));
    }

    public function unarchive(Request $request, string $ticket): JsonResponse
    {
        $t = $this->ticket($ticket);

        return $this->respond($this->tickets->unarchive($t, Actor::fromApi($request)));
    }

    /** GET /api/tickets/{ticket}/events, newest first. */
    public function events(Request $request, string $ticket): JsonResponse
    {
        $t = $this->ticket($ticket);
        $limit = (int) ($request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:200']])['limit'] ?? 100);
        $view = new TicketView;

        return response()->json(['data' => TicketEvent::query()->where('ticket_id', $t->id)->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($e) => $view->event($e))->all()]);
    }

    /** GET /api/projects/{id}/activity?since=&limit=: ticket events of the project, newest first (Captain's digests). */
    public function activity(Request $request, string $id): JsonResponse
    {
        $project = $this->projectById($id);
        $f = $request->validate([
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $events = TicketEvent::query()->where('project_id', $project->id)->with('ticket:id,key,title')
            ->when(! empty($f['since']), fn ($q) => $q->where('created_at', '>=', TicketView::time($f['since'])))
            ->orderByDesc('id')->limit((int) ($f['limit'] ?? 100))->get();
        $view = new TicketView;

        return response()->json(['data' => $events->map(fn ($e) => [
            ...$view->event($e),
            'ticket_key' => $e->ticket?->key,
            'ticket_title' => $e->ticket?->title,
        ])->all()]);
    }

    private function respond(Ticket $ticket, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => (new TicketView)->ticket($ticket->fresh()),
            'warnings' => TicketService::warnings($ticket->project),
        ], $status);
    }
}
