<?php

namespace App\Services\Tickets;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who is writing. API: the Sanctum token's name when it is an agents.id (a differing body `actor` is a 403),
 * otherwise the body `actor` (an existing agents.id). 'tilen' is never accepted over the API: Tilen's input only
 * comes from the Bridge UI (gate session), so it can always be told apart.
 */
final class Actor
{
    public const TILEN = 'tilen';

    public const CAPTAIN = 'agent-william';

    public const MIGRATION = 'migration';

    public const TILEN_ONLY_UI = "Tilen's input only comes from the Bridge UI";

    public function __construct(
        public readonly string $id,
        public readonly string $type, // agent|human|system
        public readonly string $source, // api|ui
    ) {}

    public static function tilen(): self
    {
        return new self(self::TILEN, 'human', 'ui');
    }

    public static function migration(): self
    {
        return new self(self::MIGRATION, 'system', 'api');
    }

    public function isTilen(): bool
    {
        return $this->id === self::TILEN;
    }

    public function isCaptain(): bool
    {
        return $this->id === self::CAPTAIN;
    }

    /** Resolve the acting agent of an API write. 422 when missing/unknown/tilen, 403 when it contradicts the token. */
    public static function fromApi(Request $request): self
    {
        $given = $request->input('actor', $request->input('author'));
        if ($given !== null && ! is_string($given)) {
            throw ValidationException::withMessages(['actor' => 'The actor must be an agent id (GET /api/agents).']);
        }
        $given = $given === null ? null : trim($given);
        if ($given !== null && strcasecmp($given, self::TILEN) === 0) {
            throw ValidationException::withMessages(['actor' => self::TILEN_ONLY_UI.'; use your agent id as actor.']);
        }

        $tokenName = self::tokenAgent($request);
        if ($tokenName !== null) {
            abort_if($given !== null && $given !== '' && $given !== $tokenName, 403, "This token belongs to $tokenName; actor must be $tokenName (or omitted).");

            return new self($tokenName, 'agent', 'api');
        }

        if ($given === null || $given === '') {
            throw ValidationException::withMessages(['actor' => 'The actor field is required: your agent id (GET /api/agents).']);
        }
        if (! Agent::query()->whereKey($given)->exists()) {
            throw ValidationException::withMessages(['actor' => "Unknown actor '$given': use an existing agent id (GET /api/agents)."]);
        }

        return new self($given, 'agent', 'api');
    }

    /** The token's name when it names an existing agent (per-agent tokens), else null (shared CEO token). */
    private static function tokenAgent(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            return null;
        }
        $name = (string) $token->name;

        return $name !== '' && Agent::query()->whereKey($name)->exists() ? $name : null;
    }

    /** 'tilen' -> Tilen, 'migration' -> Migration, agent id -> agent name, free text unchanged. */
    public static function label(?string $id, ?array $names = null): ?string
    {
        if ($id === null) {
            return null;
        }
        if ($id === self::TILEN) {
            return 'Tilen';
        }
        if ($id === self::MIGRATION) {
            return 'Migration';
        }
        $names ??= self::names();

        return $names[$id] ?? $id;
    }

    /** @return array<string, string> agents.id => name */
    public static function names(): array
    {
        return Agent::query()->pluck('name', 'id')->all();
    }
}
