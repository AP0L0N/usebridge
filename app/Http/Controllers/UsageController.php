<?php

namespace App\Http\Controllers;

use App\Services\Agents\AgentInstructions;
use App\Services\Usage\UsageReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UsageController extends Controller
{
    /**
     * GET /usage ?range=yesterday|7|30 (default 7) &day=YYYY-MM-DD (filters the All agents table).
     * `cli.cursorUpload`: {allowed: may POST /usage/cursor-csv, lastImportAt: last Cursor import (ISO) or null}.
     */
    public function index(Request $request, UsageReport $report): Response
    {
        $day = $request->query('day');
        $props = $report->overview(UsageReport::normalizeRange($request->query('range')), is_string($day) ? $day : null);
        $props['cli']['cursorUpload'] = [
            'allowed' => CursorCsvController::allowed($request),
            'lastImportAt' => CursorCsvController::lastImportAt(),
        ];

        return Inertia::render('Usage/Overview', $props);
    }

    /**
     * GET /usage/agents/{agentId} ?range=yesterday|7|30. An agent with a synced profile but no usage day renders with
     * empty usage; 404 when the agent has neither. `instructions`: its profile description and the owner's edits.
     */
    public function agent(Request $request, string $agentId, UsageReport $report, AgentInstructions $instructions): Response
    {
        $profile = $instructions->profile($agentId);
        $props = $report->agent($agentId, UsageReport::normalizeRange($request->query('range')), $profile?->name);
        abort_if($props === null, 404);
        $props['instructions'] = $instructions->pageProps($agentId, AgentsController::canEdit());

        return Inertia::render('Usage/Agent', $props);
    }
}
