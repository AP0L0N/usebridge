<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\CeoQueueCompatController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\NextStepController;
use App\Http\Controllers\Api\PolymarketFeedController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectStatusController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\RemovedEndpointController;
use App\Http\Controllers\Api\RequiredInputController;
use App\Http\Controllers\Api\StatusUpdateController;
use App\Http\Controllers\Api\TicketCommentController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UsageDayController;
use App\Http\Middleware\EnsureTokenAbility;
use App\Http\Middleware\GuardPolymarketUpload;
use App\Http\Middleware\GuardUsageUpload;
use App\Http\Middleware\LegacyReadOnly;
use App\Http\Middleware\RequireWorkspaceFeature;
use App\Http\Middleware\ResolveTokenWorkspace;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;

// Every route: the token's workspace (ResolveTokenWorkspace), 120 requests/min per token (throttle:api-token) and the
// ability its route needs (EnsureTokenAbility).
Route::middleware(['auth:sanctum', ResolveTokenWorkspace::class, 'throttle:api-token', EnsureTokenAbility::class])->group(function () {
    Route::apiResource('projects', ProjectController::class)->names('api.projects');
    Route::post('projects/{id}/archive', [ProjectController::class, 'archive'])->name('api.projects.archive');
    Route::post('projects/{id}/unarchive', [ProjectController::class, 'unarchive'])->name('api.projects.unarchive');
    Route::get('status-updates', [StatusUpdateController::class, 'index'])->name('api.status-updates.index');
    Route::post('status-updates', [StatusUpdateController::class, 'store'])->name('api.status-updates.store');
    Route::delete('status-updates/{id}', [StatusUpdateController::class, 'destroy'])->name('api.status-updates.destroy');
    Route::get('next-steps', [NextStepController::class, 'index'])->name('api.next-steps.index');
    // r4 Questions for the workspace owner. GET ?project_id=&state=open|answered|closed|all (default all).
    Route::get('questions', [QuestionController::class, 'index'])->name('api.questions.index');
    // Legacy writes answer 410 once BRIDGE_LEGACY_READ_ONLY is on (tickets cutover); reads keep working.
    Route::middleware(LegacyReadOnly::class)->group(function () {
        Route::post('next-steps', [NextStepController::class, 'store'])->name('api.next-steps.store');
        Route::patch('next-steps/{id}', [NextStepController::class, 'update'])->name('api.next-steps.update');
        Route::delete('next-steps/{id}', [NextStepController::class, 'destroy'])->name('api.next-steps.destroy');
        Route::post('questions', [QuestionController::class, 'store'])->name('api.questions.store');
        Route::post('projects/{project}/questions', [QuestionController::class, 'storeForProject'])->name('api.projects.questions.store');
        Route::patch('questions/{id}', [QuestionController::class, 'update'])->name('api.questions.update');
        Route::delete('questions/{id}', [QuestionController::class, 'destroy'])->name('api.questions.destroy');
        Route::apiResource('inputs', RequiredInputController::class)->except(['show'])->names('api.inputs');
        Route::post('ceo-queue-items', [CeoQueueCompatController::class, 'store'])->name('api.ceo-queue.store');
        Route::post('ceo-queue-items/{id}/dismiss', [CeoQueueCompatController::class, 'dismiss'])->name('api.ceo-queue.dismiss');
        Route::post('ceo-queue-items/{id}/assign', [CeoQueueCompatController::class, 'assign'])->name('api.ceo-queue.assign');
    });

    // Tickets (Kanban, docs/API-TICKETS.md). Writes need the acting agent (token name or `actor`); human actors are refused.
    // {ticket} is the key (CA-12) or the id (tk-…). No delete routes anywhere: archive instead (DELETE answers 405).
    Route::get('board-columns', [BoardController::class, 'columns'])->name('api.board-columns');
    Route::get('projects/{id}/board', [BoardController::class, 'board'])->name('api.projects.board');
    Route::get('projects/{id}/activity', [TicketController::class, 'activity'])->name('api.projects.activity');
    Route::get('projects/{id}/status', [ProjectStatusController::class, 'show'])->name('api.projects.status.show');
    Route::patch('projects/{id}/status', [ProjectStatusController::class, 'update'])->name('api.projects.status.update');
    Route::get('projects/{id}/tickets', [TicketController::class, 'indexForProject'])->name('api.projects.tickets.index');
    Route::post('projects/{id}/tickets', [TicketController::class, 'storeForProject'])->name('api.projects.tickets.store');
    Route::get('tickets', [TicketController::class, 'index'])->name('api.tickets.index');
    Route::post('tickets', [TicketController::class, 'store'])->name('api.tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('api.tickets.show');
    Route::patch('tickets/{ticket}', [TicketController::class, 'update'])->name('api.tickets.update');
    Route::post('tickets/{ticket}/move', [TicketController::class, 'move'])->name('api.tickets.move');
    Route::post('tickets/{ticket}/ack-input', [TicketController::class, 'ackInput'])->name('api.tickets.ack-input');
    Route::post('tickets/{ticket}/archive', [TicketController::class, 'archive'])->name('api.tickets.archive');
    Route::post('tickets/{ticket}/unarchive', [TicketController::class, 'unarchive'])->name('api.tickets.unarchive');
    Route::get('tickets/{ticket}/events', [TicketController::class, 'events'])->name('api.tickets.events');
    Route::get('tickets/{ticket}/comments', [TicketCommentController::class, 'index'])->name('api.tickets.comments.index');
    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->name('api.tickets.comments.store');
    Route::post('comments/{id}/retract', [TicketCommentController::class, 'retract'])->name('api.comments.retract');
    Route::get('ticket-comments/feed', [TicketCommentController::class, 'feed'])->name('api.ticket-comments.feed');
    Route::get('labels', [LabelController::class, 'index'])->name('api.labels.index');
    Route::post('labels', [LabelController::class, 'store'])->name('api.labels.store');
    Route::patch('labels/{id}', [LabelController::class, 'update'])->name('api.labels.update');

    Route::get('agents', [AgentController::class, 'index'])->name('api.agents.index');
    Route::post('agents', [AgentController::class, 'store'])->name('api.agents.store');
    Route::patch('agents/{id}', [AgentController::class, 'update'])->name('api.agents.update');

    // Usage: one estimator day file per POST (raw JSON body, <= 512 KB), upsert by date.
    Route::post('usage/days', [UsageDayController::class, 'store'])
        ->middleware(GuardUsageUpload::class)
        ->name('api.usage.days.store');

    // Polymarket desk: {snapshot: feed.json, series: book_series.json} (raw JSON body, <= 1 MB). Only workspaces with the
    // polymarket feature and only a token of the workspace OWNER: 404 for everyone else (as on the web), before
    // the body is looked at. Every api/polymarket/* route must carry this middleware (PolymarketApiOwnerOnlyTest).
    Route::post('polymarket/feed', [PolymarketFeedController::class, 'store'])
        ->middleware([RequireWorkspaceFeature::class.':'.Workspace::FEATURE_POLYMARKET.',owner', GuardPolymarketUpload::class])
        ->name('api.polymarket.feed.store');

    // r0 CEO queue, kept for agents: items become Required inputs (see CeoQueueCompatController).
    Route::get('ceo-queue-items', [CeoQueueCompatController::class, 'index'])->name('api.ceo-queue.index');

    // Removed in r1: answer 410 Gone instead of 404 so old callers get a clear message.
    Route::any('{removed}/{rest?}', RemovedEndpointController::class)
        ->where('removed', 'milestones|work-items|risks|ops-digest')
        ->where('rest', '.*')
        ->name('api.removed');
});
