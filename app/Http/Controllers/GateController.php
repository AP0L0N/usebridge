<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GateController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get('bridge_gate_unlocked') === true) {
            return redirect()->route('portfolio');
        }

        return Inertia::render('Gate');
    }

    public function unlock(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $expected = (string) config('bridge.gate_password');
        if (! hash_equals($expected, (string) $request->input('password'))) {
            return back()->withErrors(['password' => 'Wrong password.']);
        }

        $request->session()->put('bridge_gate_unlocked', true);
        $request->session()->regenerate();

        return redirect()->intended(route('portfolio'));
    }

    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget('bridge_gate_unlocked');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('gate.show');
    }
}
