<?php

namespace App\Http\Controllers;

use App\Services\Polymarket\DeskReport;
use Inertia\Inertia;
use Inertia\Response;

class PolymarketController extends Controller
{
    /** GET /polymarket: Frank's desk, read-only. `feed: null` when no snapshot is stored. */
    public function index(DeskReport $report): Response
    {
        return Inertia::render('Polymarket/Desk', $report->props());
    }
}
