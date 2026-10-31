<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Label;
use App\Models\Project;
use App\Models\Question;
use App\Models\RequiredInput;
use App\Models\Ticket;
use App\Services\InputResolution;
use App\Services\QuestionAnswer;
use App\Services\Tickets\Actor;
use App\Services\Tickets\BoardView;
use App\Services\Tickets\HumanActors;
use App\Services\Tickets\ProjectStatusService;
use App\Services\Tickets\TicketView;
use App\Support\BoardColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * The Kanban board (SPEC §2). The old sections (status feed, Required inputs, Next steps, Questions) are no longer
     * shown; their tables stay as read-only history. Props are closures so partial reloads (30 s poll: only=board,
     * ticket modal: only=ticket) compute just what they ask for. ?done=all lists every Done ticket ("Show N older").
     */
    public function show(Request $request, string $slug, ?Ticket $ticket = null): Response
    {
        $project = Project::query()->where('slug', $slug)->firstOrFail();
        $view = new TicketView;
        $doneSince = $request->query('done') === 'all' ? Carbon::createFromTimestamp(0) : null;

        return Inertia::render('Board', [
            'board' => fn () => (new BoardView($view))->build($project, $doneSince),
            'statusHistory' => fn () => (new ProjectStatusService)->history($project, $view, 50),
            'people' => fn () => self::people(HumanActors::viewerId()),
            'labels' => fn () => Label::query()->orderBy('name')->get()->map->toView()->values()->all(),
            'doneAll' => $doneSince !== null,
            // Column labels editor: logged-in workspace members only.
            'columnLabelsUrl' => $request->user() !== null ? route('projects.column-labels.update', $project->slug) : null,
            'defaultColumns' => BoardColumns::all(),
            'ticket' => fn () => $ticket ? self::ticketDetail($ticket, $view) : null,
            'now' => now()->toIso8601String(),
        ]);
    }

    /** Ticket detail for the modal: detail() plus when the signed-in user last opened it (the thread's "New" divider). */
    public static function ticketDetail(Ticket $ticket, TicketView $view): array
    {
        return [...$view->detail($ticket), 'tilen_last_seen_at' => $ticket->viewerReadState?->last_seen_at?->toIso8601String()];
    }

    /**
     * The workspace's people (the viewer first, role "You"; then the owner and members) and agents, for avatars, role
     * tags and the assignee picker. Human ids are actor ids (HumanActors). @return list<array{id:string,name:string,role:string,type:string}>
     */
    public static function people(string $viewer): array
    {
        $workspace = HumanActors::workspace();
        $humans = collect(HumanActors::users($workspace))
            ->map(function ($u) use ($workspace, $viewer) {
                $id = HumanActors::idFor($u, $workspace);

                return [
                    'id' => $id,
                    'name' => (string) Actor::label($id, [Actor::TILEN => HumanActors::ownerFirstName($workspace), $id => $u->name]),
                    'role' => $id === $viewer ? 'You' : ($workspace?->isOwnedBy($u) ? 'Owner' : 'Member'),
                    'type' => 'human',
                ];
            })
            ->sortBy(fn (array $h) => $h['id'] === $viewer ? 0 : 1)->values()->all();

        return [
            ...$humans,
            ...Agent::query()->orderBy('name')->get(['id', 'name', 'role_label'])
                ->map(fn (Agent $a) => ['id' => $a->id, 'name' => $a->name, 'role' => (string) $a->role_label, 'type' => 'agent'])->all(),
        ];
    }

    /** Web-session tick/untick (CSRF-protected web route). resolved_by = the signed-in user's actor id. */
    public function resolveInput(Request $request, string $slug, string $id): RedirectResponse
    {
        $data = $request->validate(['resolved' => ['required', 'boolean']]);
        $project = Project::query()->where('slug', $slug)->firstOrFail();
        $input = RequiredInput::query()->where('project_id', $project->id)->findOrFail($id);
        $data['resolved'] ? InputResolution::resolve($input, Actor::forUser($request->user())->id) : InputResolution::reopen($input);

        return back(303);
    }

    /** r4 web-session answer (CSRF-protected web route); same rules as the API PATCH. */
    public function answerQuestion(Request $request, string $slug, string $id): RedirectResponse
    {
        $data = $request->validate(QuestionAnswer::rules());
        $project = Project::query()->where('slug', $slug)->firstOrFail();
        $question = Question::query()->where('project_id', $project->id)->where('state', '!=', 'closed')->findOrFail($id);
        QuestionAnswer::answer($question, $data['answer_choice'] ?? null, $data['answer_text'] ?? null)->save();

        return back(303);
    }

    /** Archive/unarchive from the UI: logged in project_status_events as the signed-in user/human/ui, nothing is deleted. */
    public function archive(Request $request, ProjectStatusService $status, string $slug): JsonResponse|RedirectResponse
    {
        $project = Project::query()->where('slug', $slug)->firstOrFail();
        $status->archive($project, Actor::forUser($request->user()));

        return $this->archiveResponse($request, $status, $project);
    }

    public function unarchive(Request $request, ProjectStatusService $status, string $slug): JsonResponse|RedirectResponse
    {
        $project = Project::query()->where('slug', $slug)->firstOrFail();
        $status->unarchive($project, Actor::forUser($request->user()));

        return $this->archiveResponse($request, $status, $project);
    }

    private function archiveResponse(Request $request, ProjectStatusService $status, Project $project): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return back(303);
        }

        return response()->json(['data' => ['project_id' => $project->id, 'current' => $status->current($project->fresh(), new TicketView)]]);
    }
}
