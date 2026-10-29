<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FindsTickets;
use App\Models\Project;
use App\Models\ProjectReadState;
use App\Models\Ticket;
use App\Models\TicketReadState;
use App\Services\Tickets\Actor;
use App\Services\Tickets\BoardView;
use App\Services\Tickets\ProjectStatusService;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketView;
use App\Support\BoardColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Tilen's ticket writes from the Bridge UI (gate session, CSRF). Actor 'tilen', type human, source ui.
 * Every endpoint answers JSON when the request wants it (Accept: application/json), otherwise redirects back.
 */
class TicketController extends Controller
{
    use FindsTickets;

    public function __construct(private readonly TicketService $tickets) {}

    /** Deep link: the board with the ticket open (modal on desktop, full-screen sheet on mobile). */
    public function show(Request $request, ProjectController $projects, string $slug, string $key): Response
    {
        $ticket = $this->ticket($key, $this->projectBySlug($slug));

        return $projects->show($request, $slug, $ticket);
    }

    /** Quick add: {title*, status backlog|todo (default backlog)}. */
    public function store(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $project = $this->projectBySlug($slug);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:140'],
            'status' => ['nullable', Rule::in([BoardColumns::BACKLOG, BoardColumns::TODO])],
        ]);
        $ticket = $this->tickets->create($project, ['title' => $data['title'], 'status' => $data['status'] ?? BoardColumns::BACKLOG], Actor::tilen());

        return $this->respond($request, $ticket, 201);
    }

    public function update(Request $request, string $slug, string $key): JsonResponse|RedirectResponse
    {
        $ticket = $this->ticket($key, $this->projectBySlug($slug));
        $data = $request->validate(TicketService::rules(false));

        return $this->respond($request, $this->tickets->update($ticket, $data, Actor::tilen()));
    }

    /** {status*, position|before_id|after_id, ask… (required when entering waiting_tilen without an ask)}. */
    public function move(Request $request, string $slug, string $key): JsonResponse|RedirectResponse
    {
        $ticket = $this->ticket($key, $this->projectBySlug($slug));
        $data = $request->validate([
            'status' => ['required', Rule::in(BoardColumns::keys())],
            ...TicketService::placementRules(),
            ...TicketService::askRules(),
        ]);

        return $this->respond($request, $this->tickets->move($ticket, $data, Actor::tilen()));
    }

    /**
     * {body (required unless input_choice is one of the ask options), is_tilen_input, input_choice}.
     * An input comment sets tilen_input_at (PM pickup); "Change answer" is simply another input comment.
     */
    public function comment(Request $request, string $slug, string $key): JsonResponse|RedirectResponse
    {
        $ticket = $this->ticket($key, $this->projectBySlug($slug));
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:10000'],
            'is_tilen_input' => ['sometimes', 'boolean'],
            'input_choice' => ['nullable', 'string', 'max:60'],
        ]);
        $choice = TicketService::clean($data['input_choice'] ?? null);
        $isInput = (bool) ($data['is_tilen_input'] ?? false) || $choice !== null;
        if ($choice !== null && $choice !== 'other' && ! in_array($choice, $ticket->ask_options ?? [], true)) {
            throw ValidationException::withMessages(['input_choice' => 'Choose one of the ask options or "other".']);
        }
        $body = TicketService::clean($data['body'] ?? null);
        if ($body === null) {
            if ($choice === null || $choice === 'other') {
                throw ValidationException::withMessages(['body' => $choice === 'other' ? 'Add a note for "Something else".' : 'Write a comment.']);
            }
            $body = $choice;
        }
        $comment = $this->tickets->comment($ticket, Actor::tilen(), $body, $isInput, $choice);

        if ($request->expectsJson()) {
            return response()->json(['data' => (new TicketView)->comment($comment->fresh(), $ticket->fresh())], 201);
        }

        return back(303);
    }

    public function archive(Request $request, string $slug, string $key): JsonResponse|RedirectResponse
    {
        $ticket = $this->ticket($key, $this->projectBySlug($slug));

        return $this->respond($request, $this->tickets->archive($ticket, Actor::tilen()));
    }

    public function unarchive(Request $request, string $slug, string $key): JsonResponse|RedirectResponse
    {
        $ticket = $this->ticket($key, $this->projectBySlug($slug));

        return $this->respond($request, $this->tickets->unarchive($ticket, Actor::tilen()));
    }

    /** Tilen opened the ticket: clears unread_for_tilen. */
    public function seen(Request $request, string $slug, string $key): JsonResponse|RedirectResponse
    {
        $ticket = $this->ticket($key, $this->projectBySlug($slug));
        TicketReadState::query()->updateOrCreate(['user' => Actor::TILEN, 'ticket_id' => $ticket->id], ['last_seen_at' => Carbon::now()]);

        return $this->respond($request, $ticket);
    }

    /** Tilen opened the project: last_seen_at + status_seen_at (the status-change toast shows once). */
    public function projectSeen(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $project = $this->projectBySlug($slug);
        $now = Carbon::now();
        ProjectReadState::query()->updateOrCreate(['user' => Actor::TILEN, 'project_id' => $project->id], ['last_seen_at' => $now, 'status_seen_at' => $now]);

        return $request->expectsJson() ? response()->json(['data' => (new BoardView)->project($project->fresh())]) : back(303);
    }

    /** Tilen's status override {status, note}: set_by tilen, human, source ui. Archive/reopen allowed. */
    public function status(Request $request, ProjectStatusService $status, string $slug): JsonResponse|RedirectResponse
    {
        $project = $this->projectBySlug($slug);
        $data = $request->validate([
            'status' => ['required', Rule::in(Project::STATUSES)],
            'note' => ['nullable', 'string', 'max:280'],
        ]);
        $event = $status->set($project, $data['status'], TicketService::clean($data['note'] ?? null), Actor::tilen());
        if (! $request->expectsJson()) {
            return back(303);
        }
        $view = new TicketView;

        return response()->json([
            'data' => ['project_id' => $project->id, 'current' => $status->current($project->fresh(), $view), 'event' => $status->event($event, $view)],
            'warnings' => TicketService::warnings($project),
        ]);
    }

    private function respond(Request $request, Ticket $ticket, int $code = 200): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return back(303);
        }

        return response()->json(['data' => (new TicketView)->ticket($ticket->fresh()), 'warnings' => TicketService::warnings($ticket->project)], $code);
    }
}
