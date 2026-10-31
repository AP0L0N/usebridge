<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AccountDeletionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\Auth\TokenController;
use App\Http\Controllers\CursorCsvController;
use App\Http\Controllers\GateController;
use App\Http\Controllers\Pages\AccountPageController;
use App\Http\Controllers\Pages\AuthPageController;
use App\Http\Controllers\Pages\LegalPageController;
use App\Http\Controllers\Pages\OnboardingController;
use App\Http\Controllers\PolymarketController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectSetupController;
use App\Http\Controllers\StatusUpdateController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UsageController;
use App\Http\Middleware\EnsureWorkspaceGate;
use App\Http\Middleware\LegacyReadOnly;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\RequireWorkspaceFeature;
use App\Models\Workspace;
use App\Support\Legal\LegalPages;
use Illuminate\Support\Facades\Route;

// Legal pages (EN + SL): public, no login and no gate; the footer on every page links to them.
foreach (LegalPages::PAGES as $id => $page) {
    Route::get($page['path'], LegalPageController::class)->defaults('legalPage', $id)->name('legal.'.$id);
}

Route::get('/gate', [GateController::class, 'show'])->name('gate.show');
Route::post('/gate', [GateController::class, 'unlock'])->name('gate.unlock');
Route::post('/gate/lock', [GateController::class, 'lock'])->name('gate.lock');

// Accounts (docs/MULTI-USER-PLAN.md §2, §8): Inertia screens (GET) over the JSON writes. Guests land on /login; the
// AUTH-1 gate keeps working beside it ("Workspace password"); a logged-in user acts in their own workspace.
Route::get('/login', [AuthPageController::class, 'login'])->name('login.show');
Route::get('/signup', [AuthPageController::class, 'signup'])->name('signup.show');
Route::get('/forgot-password', [AuthPageController::class, 'forgot'])->name('password.request');
Route::get('/reset-password/{token}', [AuthPageController::class, 'reset'])->name('password.reset');
Route::get('/signup/form', [SignupController::class, 'form'])->name('signup.form');
Route::post('/signup', [SignupController::class, 'store'])->middleware('throttle:signup')->name('signup');
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
    Route::get('/', [AccountPageController::class, 'show'])->name('account.show');
    Route::put('/password', [PasswordController::class, 'change'])->name('account.password');
    Route::get('/sessions', [SessionController::class, 'index'])->name('account.sessions');
    Route::delete('/sessions/others', [SessionController::class, 'destroyOthers'])->name('account.sessions.others');
    Route::delete('/sessions', [SessionController::class, 'destroyAll'])->name('account.sessions.all');
    Route::get('/tokens', [AccountPageController::class, 'tokens'])->name('account.tokens');
    Route::post('/tokens', [TokenController::class, 'store'])->middleware(RequireVerifiedEmail::class)->name('account.tokens.store');
    Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])->whereNumber('id')->name('account.tokens.destroy');
    Route::post('/delete', [AccountDeletionController::class, 'request'])->name('account.delete');
    Route::get('/export', [AccountDeletionController::class, 'export'])->middleware('throttle:6,1')->name('account.export');
});

// Owner admin: user list + disable/enable only (docs/MULTI-USER-PLAN.md §5); 404 unless a logged-in is_admin user.
Route::middleware(RequireAdmin::class)->prefix('admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users');
    Route::post('/users/{id}/disable', [AdminUserController::class, 'disable'])->whereNumber('id')->name('admin.users.disable');
    Route::post('/users/{id}/enable', [AdminUserController::class, 'enable'])->whereNumber('id')->name('admin.users.enable');
});

Route::get('/welcome', OnboardingController::class)->middleware(['auth', EnsureWorkspaceGate::class])->name('onboarding');

// A logged-in user's own projects (onboarding step 1, board "Column labels"): JSON, own workspace only (§8).
Route::middleware(['auth', EnsureWorkspaceGate::class])->group(function () {
    Route::post('/projects', [ProjectSetupController::class, 'store'])->middleware('throttle:30,1')->name('projects.store');
    Route::put('/projects/{slug}/column-labels', [ProjectSetupController::class, 'updateColumnLabels'])->name('projects.column-labels.update');
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
    // Cursor dashboard CSV export (individual plan): workspace owner or the AUTH-1 gate session; 404 for others.
    Route::post('/usage/cursor-csv', [CursorCsvController::class, 'store'])->middleware('throttle:cursor-csv')->name('usage.cursor-csv');
    // Polymarket desk: only workspaces with the polymarket feature (Tilen's); 404 elsewhere.
    Route::get('/polymarket', [PolymarketController::class, 'index'])
        ->middleware(RequireWorkspaceFeature::class.':'.Workspace::FEATURE_POLYMARKET)->name('polymarket');
    // r0 screens removed in r1.
    Route::redirect('/ops', '/');
    Route::redirect('/agents', '/');
    Route::redirect('/queue', '/');
});
