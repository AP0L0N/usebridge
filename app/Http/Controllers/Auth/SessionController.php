<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\DeviceSessions;
use App\Services\Auth\LoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** The logged-in user's devices (sessions rows): list, log out the others, log out everywhere. */
class SessionController extends Controller
{
    public function __construct(private readonly DeviceSessions $sessions) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sessions->list($request->user(), $request->session()->getId())]);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is wrong.']);
        }
        // Rehashes the password (AuthenticateSession, other drivers) and re-queues this device's remember cookie.
        Auth::guard('web')->logoutOtherDevices($data['password']);
        $ended = $this->sessions->logoutOthers($request->user(), $request->session()->getId());

        return response()->json(['message' => 'Other devices were logged out.', 'ended' => $ended]);
    }

    public function destroyAll(Request $request, LoginService $login): JsonResponse
    {
        $ended = $this->sessions->logoutAll($request->user());
        $login->logout($request->session());

        return response()->json(['message' => 'Logged out of all devices.', 'ended' => $ended]);
    }
}
