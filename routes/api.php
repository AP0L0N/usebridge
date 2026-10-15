<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\CeoQueueCompatController;
use App\Http\Controllers\Api\NextStepController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RemovedEndpointController;
use App\Http\Controllers\Api\RequiredInputController;
use App\Http\Controllers\Api\StatusUpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('projects', ProjectController::class)->names('api.projects');
    Route::post('projects/{id}/archive', [ProjectController::class, 'archive'])->name('api.projects.archive');
    Route::post('projects/{id}/unarchive', [ProjectController::class, 'unarchive'])->name('api.projects.unarchive');
    Route::get('status-updates', [StatusUpdateController::class, 'index'])->name('api.status-updates.index');
    Route::post('status-updates', [StatusUpdateController::class, 'store'])->name('api.status-updates.store');
    Route::delete('status-updates/{id}', [StatusUpdateController::class, 'destroy'])->name('api.status-updates.destroy');
    Route::get('next-steps', [NextStepController::class, 'index'])->name('api.next-steps.index');
    Route::post('next-steps', [NextStepController::class, 'store'])->name('api.next-steps.store');
    Route::patch('next-steps/{id}', [NextStepController::class, 'update'])->name('api.next-steps.update');
    Route::delete('next-steps/{id}', [NextStepController::class, 'destroy'])->name('api.next-steps.destroy');
    Route::apiResource('inputs', RequiredInputController::class)->except(['show'])->names('api.inputs');
    Route::get('agents', [AgentController::class, 'index'])->name('api.agents.index');
    Route::post('agents', [AgentController::class, 'store'])->name('api.agents.store');
    Route::patch('agents/{id}', [AgentController::class, 'update'])->name('api.agents.update');

    // r0 CEO queue, kept for agents: items become Required inputs (see CeoQueueCompatController).
    Route::get('ceo-queue-items', [CeoQueueCompatController::class, 'index'])->name('api.ceo-queue.index');
    Route::post('ceo-queue-items', [CeoQueueCompatController::class, 'store'])->name('api.ceo-queue.store');
    Route::post('ceo-queue-items/{id}/dismiss', [CeoQueueCompatController::class, 'dismiss'])->name('api.ceo-queue.dismiss');
    Route::post('ceo-queue-items/{id}/assign', [CeoQueueCompatController::class, 'assign'])->name('api.ceo-queue.assign');

    // Removed in r1: answer 410 Gone instead of 404 so old callers get a clear message.
    Route::any('{removed}/{rest?}', RemovedEndpointController::class)
        ->where('removed', 'milestones|work-items|risks|ops-digest')
        ->where('rest', '.*')
        ->name('api.removed');
});
