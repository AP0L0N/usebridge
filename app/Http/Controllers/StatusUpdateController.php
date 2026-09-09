<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\StatusUpdate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StatusUpdateController extends Controller
{
    public function store(Request $request, string $slug): RedirectResponse
    {
        $project = Project::query()->where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'agent_id' => ['required', 'string', 'exists:agents,id'],
        ]);

        StatusUpdate::create([
            'id' => 'su-'.Str::lower(Str::ulid()),
            'project_id' => $project->id,
            'agent_id' => $data['agent_id'],
            'body' => $data['body'],
            'created_at' => now('Europe/Ljubljana'),
        ]);

        return back()->with('success', 'Status update added.');
    }
}
