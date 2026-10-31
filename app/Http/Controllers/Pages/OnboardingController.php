<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Auth\ApiTokens;
use App\Support\BoardColumns;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /welcome: the new user's 3-step checklist (docs/MULTI-USER-PLAN.md §8 onboarding). The step state is derived
 * (has a project, has a token), nothing is stored. Creating a project and renaming columns from the web need
 * POST /projects and PATCH /projects/{slug}/columns, which are not built yet: their URLs are null until those routes
 * exist, and the screen says so instead of offering a form that cannot save.
 */
class OnboardingController extends Controller
{
    public function __invoke(Request $request, ApiTokens $tokens): Response
    {
        $user = $request->user();
        $first = Project::query()->orderBy('created_at')->first(['id', 'name', 'slug']);

        return Inertia::render('Onboarding', [
            'name' => $user->name,
            'project' => $first === null ? null : ['name' => $first->name, 'slug' => $first->slug],
            'hasToken' => $tokens->list($user) !== [],
            'emailVerified' => $user->hasVerifiedEmail(),
            'columns' => BoardColumns::all(),
            'createProjectUrl' => Route::has('projects.store') ? route('projects.store') : null,
            'saveColumnsUrl' => $first !== null && Route::has('projects.columns.update') ? route('projects.columns.update', $first->slug) : null,
        ]);
    }
}
