<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AdminUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** GET /admin/users (Inertia screen, or JSON for JSON clients) and disable / enable (RequireAdmin, JSON). */
class UserController extends Controller
{
    public function __construct(private readonly AdminUsers $admin) {}

    public function index(Request $request): JsonResponse|Response
    {
        if ($request->wantsJson()) {
            return response()->json(['data' => $this->admin->list()]);
        }

        // Only AdminUsers::KEYS per user: no counts, nothing from another workspace (§5).
        return Inertia::render('Admin/Users', ['users' => $this->admin->list(), 'meId' => $request->user()->id]);
    }

    public function disable(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => AdminUsers::row($this->admin->disable($request->user(), User::query()->findOrFail($id)))]);
    }

    public function enable(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => AdminUsers::row($this->admin->enable($request->user(), User::query()->findOrFail($id)))]);
    }
}
