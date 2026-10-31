<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\StatusUpdateIngest;
use App\Support\Workspaces\WorkspaceRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Web (gate-session) write path; kept for compatibility, same rules as the API. */
class StatusUpdateController extends Controller
{
    public function store(Request $request, string $slug, StatusUpdateIngest $ingest): RedirectResponse
    {
        $project = Project::query()->where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'agent_id' => ['required', 'string', WorkspaceRule::exists('agents', 'id')],
            ...StatusUpdateIngest::extraRules(),
        ]);

        $ingest->handle($project, $data['agent_id'], $data['body'], null, $data['inputs'] ?? [], $data['resolved_inputs'] ?? []);

        return back()->with('success', 'Status update added.');
    }
}
