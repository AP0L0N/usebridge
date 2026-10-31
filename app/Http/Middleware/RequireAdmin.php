<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** /admin: a logged-in is_admin user; 404 for everyone else (guests, users, gate sessions), so it stays invisible. */
class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User && $user->is_admin && ! $user->isBlocked(), 404);

        return $next($request);
    }
}
