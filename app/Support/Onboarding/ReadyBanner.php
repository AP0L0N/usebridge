<?php

namespace App\Support\Onboarding;

use App\Models\Project;
use App\Models\User;
use App\Services\Auth\ApiTokens;
use Illuminate\Http\Request;

/**
 * The onboarding's "done" view on the Portfolio (Figma "Onboarding / done"): GET /?ready={slug} after step 3 shows
 * "<project> is ready…" and, while the user has no API token, the "Connect your bot" card. Only for a logged-in user
 * and a project of the current workspace (WorkspaceScope); anything else is null and the Portfolio shows nothing extra.
 */
final class ReadyBanner
{
    /** @return array{project: string, slug: string, connect: bool, tokensUrl: string, copy: array<string, string>}|null */
    public static function props(Request $request): ?array
    {
        $slug = $request->query('ready');
        $user = $request->user();
        if (! is_string($slug) || $slug === '' || ! $user instanceof User) {
            return null;
        }
        $project = Project::query()->where('slug', $slug)->first(['id', 'name', 'slug']);
        if ($project === null) {
            return null;
        }

        return [
            'project' => $project->name,
            'slug' => $project->slug,
            'connect' => app(ApiTokens::class)->list($user) === [],
            'tokensUrl' => route('account.tokens', absolute: false),
            'copy' => __('onboarding.done'),
        ];
    }
}
