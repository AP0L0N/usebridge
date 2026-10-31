<?php

namespace App\Support\Workspaces;

use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * The workspace this request (or console run) acts in; drives the BelongsToWorkspace global scope.
 *
 * - HTTP: WorkspaceBoundary marks every web/api request as `required`; the logged-in user's workspace
 *   (EnsureUserWorkspace) or the API token (ResolveTokenWorkspace) then sets it. Owned queries without one throw MissingWorkspace.
 * - Console / migrations / seeders (not required, none set): queries are not scoped, and new rows go to the parent
 *   project's/ticket's workspace, else the default workspace (bridge.default_workspace).
 */
final class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    private bool $required = false;

    private ?Workspace $default = null;

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function require(bool $required = true): void
    {
        $this->required = $required;
    }

    /** @return array{0: ?Workspace, 1: bool} */
    public function snapshot(): array
    {
        return [$this->workspace, $this->required];
    }

    /** @param  array{0: ?Workspace, 1: bool}  $state */
    public function restore(array $state): void
    {
        [$this->workspace, $this->required] = $state;
    }

    /** Run $fn acting in $workspace (console tools, tests), then restore the previous state. */
    public function runAs(Workspace $workspace, callable $fn): mixed
    {
        $state = $this->snapshot();
        $this->workspace = $workspace;
        try {
            return $fn();
        } finally {
            $this->restore($state);
        }
    }

    public function hasFeature(string $feature): bool
    {
        return $this->workspace?->hasFeature($feature) ?? false;
    }

    /**
     * A feature only the workspace owner sees (Polymarket): on for the workspace, and $user (default: the web login)
     * owns it. No user = no access (fails closed); members never see it. API routes pass $request->user() (the token
     * user), because the web guard is empty there.
     */
    public function hasOwnerFeature(string $feature, mixed $user = null): bool
    {
        if (! $this->hasFeature($feature)) {
            return false;
        }
        $user ??= Auth::guard('web')->user();

        return $user !== null && $this->workspace !== null
            && (int) $this->workspace->owner_user_id === (int) $user->getAuthIdentifier();
    }

    /** The owner's workspace (bridge.default_workspace); null before the backfill migration ran. */
    public function default(): ?Workspace
    {
        $slug = (string) (config('bridge.default_workspace') ?: 'main');
        if ($this->default === null || $this->default->slug !== $slug) {
            $this->default = Workspace::query()->where('slug', $slug)->first();
        }

        return $this->default;
    }

    /** workspace_id for a new row when the model has no parent to inherit from. Fails closed in HTTP requests. */
    public function idForWrite(string $model = ''): ?int
    {
        if ($this->workspace !== null) {
            return $this->workspace->id;
        }
        if ($this->required) {
            throw new MissingWorkspace($model);
        }

        return $this->default()?->id;
    }
}
