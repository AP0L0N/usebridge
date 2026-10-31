<?php

namespace App\Http\Controllers;

use App\Support\WorkspaceGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** AUTH-1 gate (App\Support\WorkspaceGate): 404 once retired (bridge.gate_enabled false); fails closed when unset. */
class GateController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        abort_unless(WorkspaceGate::enabled(), 404);
        if (WorkspaceGate::unlocked($request)) {
            return redirect()->route('portfolio');
        }

        return Inertia::render('Gate', ['configured' => WorkspaceGate::configured()]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        abort_unless(WorkspaceGate::enabled(), 404);
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! WorkspaceGate::accepts((string) $request->input('password'))) {
            return back()->withErrors(['password' => WorkspaceGate::configured()
                ? 'Wrong password.'
                : 'The workspace gate is not configured. Log in with your email.']);
        }

        $request->session()->put(WorkspaceGate::SESSION_KEY, true);
        $request->session()->regenerate();

        return redirect()->intended(route('portfolio'));
    }

    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget(WorkspaceGate::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route(WorkspaceGate::enabled() ? 'gate.show' : 'login');
    }
}
