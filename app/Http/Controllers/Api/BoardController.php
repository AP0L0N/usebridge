<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FindsTickets;
use App\Http\Controllers\Controller;
use App\Services\Tickets\BoardView;
use App\Services\Tickets\TicketView;
use App\Support\BoardColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
