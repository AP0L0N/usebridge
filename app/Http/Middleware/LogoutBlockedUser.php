<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * web group: a logged-in user who was disabled or asked for deletion mid-session is logged out on the next request
 * (their sessions rows are deleted too, but this also covers other session drivers and remember-me logins).
 */
class LogoutBlockedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User && $user->isBlocked()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $message = $user->disabled_at !== null ? 'This account is disabled.' : 'This account is scheduled for deletion.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 401)
                : redirect()->route('gate.show');
        }

        return $next($request);
    }
}
