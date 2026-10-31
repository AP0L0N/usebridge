<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Support\BoardColumns;
use App\Support\TicketPrefix;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A logged-in user's own project setup from the web (docs/MULTI-USER-PLAN.md §8 onboarding): create a project in their
 * workspace (no PM needed: a new workspace has no agents) and rename its board columns. Owner / member only (the route
 * runs in the user's home workspace, checked again here); another workspace's project is 404 (WorkspaceScope).
 * Slug and ticket prefix are still globally unique (§1): a taken one is a 422 on that field that does not say where.
 * POST /api/projects (agents, owner_agent_id required) is a separate path and unchanged.
 */
class ProjectSetupController extends Controller
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(private readonly CurrentWorkspace $workspace) {}

    /** POST /projects {name, slug, ticket_prefix, description?} → 201 {data: {id, name, slug, ticket_prefix, description, column_labels_url}}. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeMember($request);
        $request->merge([
            'slug' => is_string($request->input('slug')) ? Str::lower(trim($request->input('slug'))) : $request->input('slug'),
            'ticket_prefix' => is_string($request->input('ticket_prefix')) ? Str::upper(trim($request->input('ticket_prefix'))) : $request->input('ticket_prefix'),
        ]);
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'regex:'.self::SLUG_PATTERN, 'unique:projects,slug'],
            'ticket_prefix' => ['required', 'string', 'regex:'.TicketPrefix::PATTERN, 'unique:projects,ticket_prefix'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
        $data = $request->validate($rules, self::messages());

        try {
            $project = Project::create([
                'id' => 'proj-'.Str::lower((string) Str::ulid()),
                'name' => trim($data['name']),
                'slug' => $data['slug'],
                'ticket_prefix' => $data['ticket_prefix'],
                'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                'last_activity_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race with another create: validating again answers the same 422 "taken" as above.
            $request->validate($rules, self::messages());
            throw $e;
        }

        return response()->json(['data' => [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'ticket_prefix' => $project->ticket_prefix,
            'description' => $project->description,
            'column_labels_url' => route('projects.column-labels.update', $project->slug),
        ]], 201);
    }

    /**
     * PUT /projects/{slug}/column-labels {column_labels: {key: label}} → {data: {columns}}. Known keys only, each label
     * 1–40 characters after trimming; keys not sent keep their current label, a label equal to the default clears it.
     */
    public function updateColumnLabels(Request $request, string $slug): JsonResponse
    {
        $this->authorizeMember($request);
        $project = Project::query()->where('slug', $slug)->firstOrFail();

        $labels = $request->input('column_labels');
        if (is_array($labels)) {
            $request->merge(['column_labels' => array_map(fn ($v) => is_string($v) ? trim($v) : $v, $labels)]);
        }
        $data = $request->validate([
            'column_labels' => ['required', 'array', 'min:1', function (string $attr, mixed $value, \Closure $fail) {
                $unknown = array_diff(array_keys((array) $value), BoardColumns::keys());
                if ($unknown !== []) {
                    $fail('Unknown column: '.implode(', ', $unknown).'. Columns are '.implode(', ', BoardColumns::keys()).'.');
                }
            }],
            'column_labels.*' => ['required', 'string', 'min:1', 'max:'.BoardColumns::LABEL_MAX],
        ], [
            'column_labels.*.required' => __('onboarding.errors.column_required'),
            'column_labels.*.min' => __('onboarding.errors.column_required'),
            'column_labels.*.max' => __('onboarding.errors.column_max', ['max' => BoardColumns::LABEL_MAX]),
        ]);

        $own = array_merge($project->column_labels ?? [], $data['column_labels']);
        $own = array_filter($own, fn ($label, $key) => in_array($key, BoardColumns::keys(), true) && $label !== BoardColumns::label($key), ARRAY_FILTER_USE_BOTH);
        $project->forceFill(['column_labels' => $own === [] ? null : $own])->save();

        return response()->json(['data' => ['columns' => $project->boardColumns()]]);
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'slug.unique' => __('onboarding.errors.slug_taken'),
            'slug.regex' => __('onboarding.errors.slug_regex'),
            'ticket_prefix.unique' => __('onboarding.errors.prefix_taken'),
            'ticket_prefix.regex' => __('onboarding.errors.prefix_regex'),
        ];
    }

    /** The route runs in the user's home workspace; a user who is neither its owner nor a member gets 404. */
    private function authorizeMember(Request $request): void
    {
        $user = $request->user();
        $workspace = $this->workspace->get();
        abort_unless($user instanceof User && $workspace !== null && $workspace->hasMember($user), 404);
    }
}
