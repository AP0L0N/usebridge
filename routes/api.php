<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\CeoQueueItemController;
use App\Http\Controllers\Api\MilestoneController;
use App\Http\Controllers\Api\OpsDigestController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RiskController;
use App\Http\Controllers\Api\StatusUpdateController;
use App\Http\Controllers\Api\WorkItemController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('projects', ProjectController::class)->names('api.projects');
    Route::apiResource('milestones', MilestoneController::class)->except(['show'])->names('api.milestones');
    Route::apiResource('work-items', WorkItemController::class)->except(['show'])->names('api.work-items');
    Route::apiResource('risks', RiskController::class)->except(['show'])->names('api.risks');
    Route::get('status-updates', [StatusUpdateController::class, 'index'])->name('api.status-updates.index');
    Route::post('status-updates', [StatusUpdateController::class, 'store'])->name('api.status-updates.store');
    Route::delete('status-updates/{id}', [StatusUpdateController::class, 'destroy'])->name('api.status-updates.destroy');
    Route::get('agents', [AgentController::class, 'index'])->name('api.agents.index');
    Route::post('agents', [AgentController::class, 'store'])->name('api.agents.store');
    Route::patch('agents/{id}', [AgentController::class, 'update'])->name('api.agents.update');
    Route::get('ceo-queue-items', [CeoQueueItemController::class, 'index'])->name('api.ceo-queue.index');
    Route::post('ceo-queue-items', [CeoQueueItemController::class, 'store'])->name('api.ceo-queue.store');
    Route::post('ceo-queue-items/{id}/dismiss', [CeoQueueItemController::class, 'dismiss'])->name('api.ceo-queue.dismiss');
    Route::post('ceo-queue-items/{id}/assign', [CeoQueueItemController::class, 'assign'])->name('api.ceo-queue.assign');
    Route::get('ops-digest', [OpsDigestController::class, 'index'])->name('api.ops-digest.index');
});
