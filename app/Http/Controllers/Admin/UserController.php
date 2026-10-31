<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AdminUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /admin/users and disable / enable (RequireAdmin; JSON only, the admin screen comes later). */
class UserController extends Controller
{
    public function __construct(private readonly AdminUsers $admin) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->admin->list()]);
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
