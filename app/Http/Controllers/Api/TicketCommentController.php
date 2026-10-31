<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FindsTickets;
use App\Http\Controllers\Controller;
use App\Models\TicketComment;
use App\Services\Tickets\Actor;
use App\Services\Tickets\CommentFeed;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Ticket comments over the API: agent authors only (author = actor); owner input (is_owner_input / is_tilen_input) is refused. */
class TicketCommentController extends Controller
{
    use FindsTickets;

    public function __construct(private readonly TicketService $tickets) {}

    /** Oldest first; retracted comments stay as {retracted:true, body:null}. */
    public function index(string $ticket): JsonResponse
    {
        $t = $this->ticket($ticket);
        $view = new TicketView;

        return response()->json(['data' => TicketComment::query()->where('ticket_id', $t->id)->orderBy('id')->get()
            ->map(fn ($c) => $view->comment($c, $t))->all()]);
    }

    public function store(Request $request, string $ticket): JsonResponse
    {
        $t = $this->ticket($ticket);
        $actor = Actor::fromApi($request);
        if ($request->boolean('is_owner_input') || $request->boolean('is_tilen_input') || $request->filled('input_choice')) {
            throw ValidationException::withMessages([($request->has('is_owner_input') ? 'is_owner_input' : 'is_tilen_input') => Actor::TILEN_ONLY_UI.'.']);
        }
        $data = $request->validate(TicketService::commentRules());
        $comment = $this->tickets->comment($t, $actor, $data['body']);

        return response()->json(['data' => (new TicketView)->comment($comment->fresh(), $t->fresh())], 201);
    }

    /** POST /api/comments/{id}/retract: own comments only; soft (the row stays, the body is hidden). */
    public function retract(Request $request, string $id): JsonResponse
    {
        $comment = TicketComment::query()->find($id);
        abort_if(! $comment, 404, "Unknown comment '$id'.");
        $actor = Actor::fromApi($request);
        $comment = $this->tickets->retract($comment, $actor);

        return response()->json(['data' => (new TicketView)->comment($comment->fresh())]);
    }

    /**
     * GET /api/ticket-comments/feed: Captain's answers relay.
     * after=<comment id> | since=ISO (after wins), author=humans|agents|all (default humans; 'tilen' = humans, old name),
     * inputs_only, project_id, limit ≤200.
     */
    public function feed(Request $request, CommentFeed $feed): JsonResponse
    {
        $f = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'since' => ['nullable', 'date'],
            'author' => ['nullable', 'in:humans,tilen,agents,all'],
            'inputs_only' => ['nullable', 'in:0,1,true,false'],
            'project_id' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $f['inputs_only'] = self::flag($f['inputs_only'] ?? false);

        return response()->json($feed->page($f));
    }
}
