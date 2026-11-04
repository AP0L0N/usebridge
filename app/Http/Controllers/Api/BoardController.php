<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FindsTickets;
use App\Http\Controllers\Controller;
use App\Services\Tickets\BoardView;
use App\Services\Tickets\OpenAsks;
use App\Services\Tickets\Questions;
use App\Services\Tickets\TicketView;
use App\Support\BoardColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoardController extends Controller
{
    use FindsTickets;

    /** GET /api/board-columns: the fixed column set, so agents never hard-code labels. */
    public function columns(): JsonResponse
    {
        return response()->json(['data' => BoardColumns::all()]);
    }

    /** GET /api/projects/{id}/board?done_since=ISO (default now-7d)&include_archived=0 */
    public function board(Request $request, string $id): JsonResponse
    {
        $project = $this->projectById($id);
        $f = $request->validate([
            'done_since' => ['nullable', 'date'],
            'include_archived' => ['nullable', 'in:0,1,true,false'],
        ]);

        return response()->json(['data' => (new BoardView)->build(
            $project,
            empty($f['done_since']) ? null : TicketView::time($f['done_since']),
            self::flag($f['include_archived'] ?? false),
        )]);
    }

    /** GET /api/projects/{id}/asks: the project's open asks (r4 Questions for you), oldest waiting_since first. */
    public function asks(string $id): JsonResponse
    {
        return response()->json(['data' => OpenAsks::list($this->projectById($id))]);
    }

    /**
     * GET /api/projects/{id}/questions?state=open|answered|closed|dismissed|all (default all): the r4 Questions panel
     * payload {open, answered, closed, has_any}, the same as the Board's `questions` prop (Questions::panel).
     */
    public function questions(Request $request, string $id): JsonResponse
    {
        $project = $this->projectById($id);
        $f = $request->validate(['state' => ['nullable', Rule::in([...Questions::STATES, 'all'])]]);

        return response()->json(['data' => Questions::panel($project, null, $f['state'] ?? 'all')]);
    }
}
