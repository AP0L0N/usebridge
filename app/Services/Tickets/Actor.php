<?php

namespace App\Services\Tickets;

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\TokenAgents;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who is writing. API: the Sanctum token's name when it is an agents.id (a differing body `actor` is a 403),
 * otherwise the body `actor` (an existing agents.id of the token's workspace), otherwise the token's own agent
 * (personal_access_tokens.agent_id, TokenAgents; go-live B3: `actor` is optional). 'tilen' is never accepted over
 * the API: human input only comes from the Bridge UI (web session), so it can always be told apart. Web users act
 * as themselves (HumanActors: 'user:<id>', or the legacy 'tilen' for the default workspace's owner).
 */
final class Actor
{
    public const TILEN = 'tilen';

    public const CAPTAIN = 'agent-william';

    public const MIGRATION = 'migration';

    public const TILEN_ONLY_UI = 'Human input only comes from the Bridge UI';

    public function __construct(
        public readonly string $id,
        public readonly string $type, // agent|human|system
        public readonly string $source, // api|ui|mcp
    ) {}

    /** The legacy owner of the default workspace (pre-multi-user data). Web requests use forUser(). */
    public static function tilen(): self
    {
        return new self(self::TILEN, 'human', 'ui');
    }

    /** A signed-in web user writing in $workspace (default: the current one). */
    public static function forUser(User $user, ?Workspace $workspace = null): self
    {
        return new self(HumanActors::idFor($user, $workspace), 'human', 'ui');
    }

    /** A human actor with this id (e.g. the workspace owner from HumanActors::ownerId()). */
    public static function human(string $id): self
    {
        return new self($id, 'human', 'ui');
    }

    public static function migration(): self
    {
        return new self(self::MIGRATION, 'system', 'api');
    }

    /** The same actor writing through the MCP server (status events record source 'mcp'; REST stays 'api'). */
    public function viaMcp(): self
    {
        return new self($this->id, $this->type, 'mcp');
    }

    public function isTilen(): bool
    {
        return $this->id === self::TILEN;
    }

    /** Any human (web UI) actor of a workspace: the legacy 'tilen' or 'user:<id>'. */
    public function isHuman(): bool
    {
        return self::isHumanId($this->id);
    }

    public static function isHumanId(?string $id): bool
    {
        return HumanActors::isHumanId($id);
    }

    public function isCaptain(): bool
    {
        return $this->id === self::CAPTAIN;
    }

    /** Resolve the acting agent of an API write. 422 when missing/unknown/tilen, 403 when it contradicts the token. */
    public static function fromApi(Request $request): self
    {
        return self::forToken($request->input('actor', $request->input('author')), $request->user());
    }

    /** Same rules for an API token user and a given `actor` (REST body or MCP tool argument). */
    public static function forToken(mixed $given, mixed $user): self
    {
        if ($given !== null && ! is_string($given)) {
            throw ValidationException::withMessages(['actor' => 'The actor must be an agent id (GET /api/agents).']);
        }
        $given = $given === null ? null : trim($given);
        if ($given !== null && strcasecmp($given, self::TILEN) === 0) {
            throw ValidationException::withMessages(['actor' => self::TILEN_ONLY_UI.'; use your agent id as actor.']);
        }

        $tokenName = self::tokenAgent($user);
        if ($tokenName !== null) {
            abort_if($given !== null && $given !== '' && $given !== $tokenName, 403, "This token belongs to $tokenName; actor must be $tokenName (or omitted).");

            return new self($tokenName, 'agent', 'api');
        }

        if ($given === null || $given === '') {
            // Go-live B3: no actor -> the token's own agent in its workspace (created on the token's first write if the
            // token predates token agents). Only a session / unbound token still has to name one.
            $agent = self::boundAgent($user);
            if ($agent === null) {
                throw ValidationException::withMessages(['actor' => 'The actor field is required: your agent id (GET /api/agents).']);
            }

            return new self($agent, 'agent', 'api');
        }
        // Agent is workspace-scoped: an agent of another workspace is "unknown" here (write isolation).
        if (! Agent::query()->whereKey($given)->exists()) {
            throw ValidationException::withMessages(['actor' => "Unknown actor '$given': use an existing agent id (GET /api/agents)."]);
        }

        return new self($given, 'agent', 'api');
    }

    /** The current token's agent id (personal_access_tokens.agent_id), provisioned when missing; null without a bound token. */
    private static function boundAgent(mixed $user): ?string
    {
        $token = $user?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            return null;
        }
        $id = $token->getAttribute('agent_id');
        if ($id !== null && Agent::query()->whereKey($id)->exists()) {
            return (string) $id;
        }

        return TokenAgents::provision($token, app(CurrentWorkspace::class)->id())?->id;
    }

    /** The token's name when it names an existing agent (per-agent tokens), else null (shared CEO token). */
    private static function tokenAgent(mixed $user): ?string
    {
        $token = $user?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            return null;
        }
        $name = (string) $token->name;

        return $name !== '' && Agent::query()->whereKey($name)->exists() ? $name : null;
    }

    /**
     * 'tilen' -> the workspace owner's first name, 'user:<id>' -> that user's name, 'migration' -> Migration,
     * agent id -> agent name, free text unchanged.
     */
    public static function label(?string $id, ?array $names = null): ?string
    {
        if ($id === null) {
            return null;
        }
        if ($id === self::TILEN) {
            return $names[self::TILEN] ?? HumanActors::ownerFirstName();
        }
        if ($id === self::MIGRATION) {
            return 'Migration';
        }
        $names ??= self::names();

        return $names[$id] ?? (HumanActors::isUserId($id) ? HumanActors::userLabel($id) : $id);
    }

    /** @return array<string, string> agents.id => name, plus the workspace's humans (HumanActors::names) */
    public static function names(): array
    {
        return [...Agent::query()->pluck('name', 'id')->all(), ...HumanActors::names()];
    }
}
