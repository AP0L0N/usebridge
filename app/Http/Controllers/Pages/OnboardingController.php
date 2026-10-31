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
 * GET /welcome: the new user's 3 steps (docs/MULTI-USER-PLAN.md §8 onboarding, Figma "Onboarding"). The step state is
 * derived (has a project), nothing is stored. Step 1 posts to POST /projects (projects.store), step 2 to
 * PUT /projects/{slug}/column-labels (projects.column-labels.update) of the first project; step 3 links to the tokens
 * page, "Skip for now" to the Portfolio with ?ready={slug} (its banner + connect card, PortfolioController).
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
            // The MCP server on APP_URL (the same URL as Account, API tokens & MCP), never the request's host.
            'mcpUrl' => AccountPageController::mcpUrl(),
            'tokensUrl' => route('account.tokens', absolute: false),
            'portfolioUrl' => route('portfolio', absolute: false),
            'copy' => __('onboarding'),
        ]);
    }
}
