<?php

namespace App\Http\Controllers;

use App\Services\Usage\UsageReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UsageController extends Controller
{
    /** GET /usage ?range=7|30 (default 7) &day=YYYY-MM-DD (filters the All agents table). */
    public function index(Request $request, UsageReport $report): Response
    {
        $day = $request->query('day');

        return Inertia::render('Usage/Overview', $report->overview(
            UsageReport::normalizeRange($request->query('range')),
            is_string($day) ? $day : null,
        ));
    }

    /** GET /usage/agents/{agentId} ?range=7|30. 404 when the agent is in no stored day. */
    public function agent(Request $request, string $agentId, UsageReport $report): Response
    {
        $props = $report->agent($agentId, UsageReport::normalizeRange($request->query('range')));
        abort_if($props === null, 404);

        return Inertia::render('Usage/Agent', $props);
    }
}
