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
 * GET /agents: every agent of the workspace (synced profiles + agents seen in usage files), each linking to
 * /usage/agents/{agentId}. POST /usage/agents/{agentId}/instructions: the workspace owner saves a proposed description
 * (a new revision, pending until the daily sync applies it; docs/agent-instructions-sync.md).
 */
class AgentsController extends Controller
{
    public function __construct(private readonly AgentInstructions $instructions) {}

    public function index(): Response
    {
        return Inertia::render('Agents/Index', ['agents' => $this->instructions->list()]);
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
