<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\GateController;
use App\Http\Controllers\PolymarketController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\StatusUpdateController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UsageController;
use App\Http\Middleware\EnsureWorkspaceGate;
use App\Http\Middleware\LegacyReadOnly;
use App\Http\Middleware\RequireWorkspaceFeature;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/gate', [GateController::class, 'show'])->name('gate.show');
Route::post('/gate', [GateController::class, 'unlock'])->name('gate.unlock');
Route::post('/gate/lock', [GateController::class, 'lock'])->name('gate.lock');

// Accounts (docs/MULTI-USER-PLAN.md §2, §8): JSON for the screens to come. The AUTH-1 gate keeps working beside them;
// a logged-in user acts in their own workspace (EnsureWorkspaceGate).
Route::post('/login', [LoginController::class, 'login'])->name('login');
Route::post('/login/cancel-deletion', [LoginController::class, 'cancelDeletion'])->name('login.cancel-deletion');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
Route::post('/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:6,1')->name('password.email');
Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
    ->middleware(['auth', 'throttle:6,1'])->name('verification.send');

Route::middleware(['auth', EnsureWorkspaceGate::class])->prefix('account')->group(function () {
    Route::get('/', fn (Request $request) => response()->json(['user' => LoginController::user($request->user())]))->name('account.show');
    Route::put('/password', [PasswordController::class, 'change'])->name('account.password');
    Route::get('/sessions', [SessionController::class, 'index'])->name('account.sessions');
    Route::delete('/sessions/others', [SessionController::class, 'destroyOthers'])->name('account.sessions.others');
    Route::delete('/sessions', [SessionController::class, 'destroyAll'])->name('account.sessions.all');
});

Route::middleware(EnsureWorkspaceGate::class)->group(function () {
    Route::get('/', PortfolioController::class)->name('portfolio');
    Route::get('/portfolio', fn () => redirect()->route('portfolio'));
    Route::get('/projects/{slug}', [ProjectController::class, 'show'])->name('projects.show');
    Route::post('/projects/{slug}/status-updates', [StatusUpdateController::class, 'store'])->name('projects.status-updates.store');
    Route::post('/projects/{slug}/inputs/{id}/resolve', [ProjectController::class, 'resolveInput'])
        ->middleware(LegacyReadOnly::class)->name('projects.inputs.resolve');
    Route::post('/projects/{slug}/questions/{id}/answer', [ProjectController::class, 'answerQuestion'])
        ->middleware(LegacyReadOnly::class)->name('projects.questions.answer');
    // Tickets: Tilen's board writes (actor 'tilen', source ui). JSON with Accept: application/json, else redirect back.
    Route::get('/projects/{slug}/tickets/{key}', [TicketController::class, 'show'])->name('projects.tickets.show');
    Route::post('/projects/{slug}/tickets', [TicketController::class, 'store'])->name('projects.tickets.store');
    Route::patch('/projects/{slug}/tickets/{key}', [TicketController::class, 'update'])->name('projects.tickets.update');
    Route::post('/projects/{slug}/tickets/{key}/move', [TicketController::class, 'move'])->name('projects.tickets.move');
    Route::post('/projects/{slug}/tickets/{key}/comments', [TicketController::class, 'comment'])->name('projects.tickets.comments.store');
    Route::post('/projects/{slug}/tickets/{key}/archive', [TicketController::class, 'archive'])->name('projects.tickets.archive');
    Route::post('/projects/{slug}/tickets/{key}/unarchive', [TicketController::class, 'unarchive'])->name('projects.tickets.unarchive');
    Route::post('/projects/{slug}/tickets/{key}/seen', [TicketController::class, 'seen'])->name('projects.tickets.seen');
    Route::post('/projects/{slug}/seen', [TicketController::class, 'projectSeen'])->name('projects.seen');
    Route::patch('/projects/{slug}/status', [TicketController::class, 'status'])->name('projects.status.update');
    Route::post('/projects/{slug}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::post('/projects/{slug}/unarchive', [ProjectController::class, 'unarchive'])->name('projects.unarchive');
    Route::get('/usage', [UsageController::class, 'index'])->name('usage');
    Route::get('/usage/agents/{agentId}', [UsageController::class, 'agent'])
        ->where('agentId', '[A-Za-z0-9_-]{1,64}')
        ->name('usage.agent');
    // Polymarket desk: only workspaces with the polymarket feature (Tilen's); 404 elsewhere.
    Route::get('/polymarket', [PolymarketController::class, 'index'])
        ->middleware(RequireWorkspaceFeature::class.':'.Workspace::FEATURE_POLYMARKET)->name('polymarket');
    // r0 screens removed in r1.
    Route::redirect('/ops', '/');
    Route::redirect('/agents', '/');
    Route::redirect('/queue', '/');
});
