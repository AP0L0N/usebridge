<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Agents\AgentInstructions;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /agents: the agents the roster sync listed (docs/agents-sync.md), grouped by section, each linking to
 * /usage/agents/{agentId}. POST /usage/agents/{agentId}/instructions: the workspace owner saves a proposed description
 * (a new revision, pending until the daily sync applies it; docs/agent-instructions-sync.md).
 */
class AgentsController extends Controller
{
    public function __construct(private readonly AgentInstructions $instructions) {}

    /** Avatar grid (README §1.4): sections with a count; each tile has Geist Mono initials and opens /usage/agents/{id}. */
    public function index(): Response
    {
        $sections = array_map(fn (array $section): array => [
            ...$section,
            'count' => count($section['agents']),
            'agents' => array_map(fn (array $a): array => [
                ...$a,
                'initials' => self::initials((string) ($a['name'] ?: $a['fullName'])),
                'url' => route('usage.agent', $a['id']),
            ], $section['agents']),
        ], $this->instructions->list());

        return Inertia::render('Agents/Index', ['sections' => $sections]);
    }

    /** "Ava" → "AV", "Sam Rivera" → "SR" (two letters, upper case). */
    public static function initials(string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = count($words) >= 2
            ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1)
            : mb_substr($words[0] ?? '?', 0, 2);

        return mb_strtoupper($letters);
    }

    /** {description}. 403 unless the workspace owner, 404 for an agent this workspace does not know. */
    public function saveInstructions(Request $request, string $agentId): JsonResponse
    {
        abort_unless(self::canEdit(), 403, 'Only the workspace owner can edit agent instructions.');
        abort_unless($this->instructions->knows($agentId), 404);
        $data = $request->validate(['description' => ['required', 'string', 'max:20000']]);
        if ($this->instructions->profile($agentId)?->description === $data['description']) {
            throw ValidationException::withMessages(['description' => 'This is already the agent\'s current description.']);
        }

        /** @var User $user */
        $user = $request->user();
        $edit = $this->instructions->propose($user, $agentId, $data['description']);

        return response()->json(['data' => AgentInstructions::presentEdit($edit)], 201);
    }

    /** The logged-in user owns the current workspace (EnsureUserWorkspace has set it). */
    public static function canEdit(): bool
    {
        $workspace = app(CurrentWorkspace::class)->get();
        $user = Auth::guard('web')->user();

        return $user instanceof User && $workspace !== null && $workspace->isOwnedBy($user);
    }
}
