<?php

use App\Http\Controllers\Api\AgentController;
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
    Route::apiResource('inputs', RequiredInputController::class)->except(['show'])->names('api.inputs');
    Route::get('agents', [AgentController::class, 'index'])->name('api.agents.index');
    Route::post('agents', [AgentController::class, 'store'])->name('api.agents.store');
    Route::patch('agents/{id}', [AgentController::class, 'update'])->name('api.agents.update');

    // Removed in r1: answer 410 Gone instead of 404 so old callers get a clear message.
    Route::any('{removed}/{rest?}', RemovedEndpointController::class)
        ->where('removed', 'milestones|work-items|risks|ceo-queue-items|ops-digest')
        ->where('rest', '.*')
        ->name('api.removed');
});
