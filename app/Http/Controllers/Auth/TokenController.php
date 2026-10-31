<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\ApiTokens;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The logged-in user's API tokens (docs/MULTI-USER-PLAN.md §8 tokens): list, create (verified only), revoke. */
class TokenController extends Controller
{
    public function __construct(private readonly ApiTokens $tokens) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tokens->list($request->user())]);
    }

    public function store(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'scope' => ['required', 'string'],
            'expires' => ['nullable', 'integer'],
        ]);
        $new = $this->tokens->create($request->user(), $current->get(), $data['name'], $data['scope'],
            isset($data['expires']) ? (int) $data['expires'] : null);

        return response()->json(['data' => ApiTokens::present($new['token']), 'plain_text_token' => $new['plain_text']], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless($this->tokens->revoke($request->user(), $id), 404);

        return response()->json(['message' => 'Token revoked.']);
    }
}
