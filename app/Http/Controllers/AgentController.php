<?php

namespace App\Http\Controllers;

use App\Services\LoadService;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function __invoke(LoadService $load): Response
    {
        return Inertia::render('Agents', [
            'rows' => $load->buildAgentLoadRows(),
        ]);
    }
}
