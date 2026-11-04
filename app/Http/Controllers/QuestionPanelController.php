<?php

namespace App\Http\Controllers;

use App\Http\Middleware\LegacyReadOnly;
use App\Models\Project;
use App\Models\Ticket;
use App\Services\Tickets\Actor;
use App\Services\Tickets\Questions;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketView;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * r4 Questions panel writes (Bridge UI session, same group and rules as the ticket comment route): any signed-in user
 * of the workspace, acting as themselves (Actor::forUser, source ui). JSON with Accept: application/json, else a 303
 * back. {id} is the question id (Questions::id, 'q-' + ticket id); another workspace's project is a 404.
 */
class QuestionPanelController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    /**
     * {choice?: one of the options, text?: ≤2000}: posts the owner input comment exactly like the drawer's "Your input"
     * (TicketService::uiComment), so needs_pm_pickup, the commented event, the feed and the via label are the same.
     * Posting again while answered = "Change answer": the new input supersedes the pending one (TicketService::comment,
     * never deleted). 409 once closed (picked up / moved on).
     * A legacy questions.id (r4 v1 table) still goes to the old answer action (410 once BRIDGE_LEGACY_READ_ONLY is on).
     */
    public function answer(Request $request, ProjectController $legacy, string $slug, string $id): Response
    {
        if (Questions::ticketIdFrom($id) === null) {
            return app(LegacyReadOnly::class)->handle($request, fn (Request $r) => $legacy->answerQuestion($r, $slug, $id));
        }
        [$project, $round] = $this->question($slug, $id);
        abort_if($round['state'] === 'closed', 409, 'Question is closed');
        $data = $request->validate([
            'choice' => ['nullable', 'string', 'max:60'],
            'text' => ['nullable', 'string', 'max:2000'],
        ]);
        $options = $round['ticket']->ask_options ?? [];
        $choice = TicketService::clean($data['choice'] ?? null);
        $text = TicketService::clean($data['text'] ?? null);
        if ($choice !== null && ! in_array($choice, $options, true)) {
            throw ValidationException::withMessages(['choice' => $options === [] ? 'This question takes a written answer.' : 'Choose one of the options.']);
        }
        if ($choice === null && $text === null) {
            throw ValidationException::withMessages(['text' => 'Pick an option or write an answer.']);
        }

        $ticket = Ticket::query()->findOrFail($round['ticket']->id)->setRelation('project', $project);
        $comment = $this->tickets->uiComment($ticket, Actor::forUser($request->user()), $text, $choice, true);

        return $this->respond($request, $project, $id, 201, ['comment' => (new TicketView)->comment($comment->fresh(), $ticket->fresh())]);
    }

    /** Hide an open question (event ask_dismissed). The ticket, its pickup flag and comments are untouched. */
    public function dismiss(Request $request, string $slug, string $id): Response
    {
        [$project, $round] = $this->question($slug, $id);
        if ($round['state'] === 'open') {
            $this->tickets->event($round['ticket'], Questions::DISMISSED, null, ['ask' => $round['text']], Actor::forUser($request->user()));
        } elseif ($round['state'] !== 'dismissed') {
            abort(409, 'Only an open question can be dismissed.');
        }

        return $this->respond($request, $project, $id);
    }

    /** Bring a dismissed question back to open (event ask_restored). */
    public function restore(Request $request, string $slug, string $id): Response
    {
        [$project, $round] = $this->question($slug, $id);
        if ($round['state'] === 'dismissed') {
            $this->tickets->event($round['ticket'], Questions::RESTORED, null, ['ask' => $round['text']], Actor::forUser($request->user()));
        } elseif ($round['state'] !== 'open') {
            abort(409, 'Only a dismissed question can be restored.');
        }

        return $this->respond($request, $project, $id);
    }

    /** @return array{0: Project, 1: array} */
    private function question(string $slug, string $id): array
    {
        $project = Project::query()->where('slug', $slug)->firstOrFail();
        $round = Questions::find($project, $id);
        abort_if($round === null, 404, "Unknown question '$id'.");

        return [$project, $round];
    }

    private function respond(Request $request, Project $project, string $id, int $code = 200, array $extra = []): Response
    {
        if (! $request->expectsJson()) {
            return back(303);
        }

        return response()->json(['data' => Questions::view($project, Questions::find($project, $id)), ...$extra], $code);
    }
}
