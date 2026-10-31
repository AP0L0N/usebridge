<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Auth\ApiTokens;
use App\Support\BoardColumns;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /welcome: the new user's 3-step checklist (docs/MULTI-USER-PLAN.md §8 onboarding). The step state is derived
 * (has a project, has a token), nothing is stored. Step 1 posts to POST /projects (projects.store), step 2 to
 * PUT /projects/{slug}/column-labels (projects.column-labels.update) of the first project.
 */
class OnboardingController extends Controller
{
    public function __invoke(Request $request, ApiTokens $tokens): Response
    {
        $user = $request->user();
        $first = Project::query()->orderBy('created_at')->first(['id', 'name', 'slug', 'column_labels']);

        return Inertia::render('Onboarding', [
            'name' => $user->name,
            'project' => $first === null ? null : ['name' => $first->name, 'slug' => $first->slug],
            'hasToken' => $tokens->list($user) !== [],
            'emailVerified' => $user->hasVerifiedEmail(),
            'columns' => $first?->boardColumns() ?? BoardColumns::all(),
            'defaultColumns' => BoardColumns::all(),
            'createProjectUrl' => route('projects.store'),
            // Null until there is a project; step 1's answer carries the URL for the project it created.
            'saveColumnsUrl' => $first === null ? null : route('projects.column-labels.update', $first->slug),
        ]);
    }
}
