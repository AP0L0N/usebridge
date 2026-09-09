<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\GateController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\StatusUpdateController;
use App\Http\Middleware\EnsureWorkspaceGate;
use Illuminate\Support\Facades\Route;

Route::get('/gate', [GateController::class, 'show'])->name('gate.show');
Route::post('/gate', [GateController::class, 'unlock'])->name('gate.unlock');
Route::post('/gate/lock', [GateController::class, 'lock'])->name('gate.lock');

Route::middleware(EnsureWorkspaceGate::class)->group(function () {
    Route::get('/', PortfolioController::class)->name('portfolio');
    Route::get('/portfolio', fn () => redirect()->route('portfolio'));
    Route::get('/projects/{slug}', [ProjectController::class, 'show'])->name('projects.show');
    Route::post('/projects/{slug}/status-updates', [StatusUpdateController::class, 'store'])->name('projects.status-updates.store');
    Route::get('/ops', OpsController::class)->name('ops');
    Route::get('/agents', AgentController::class)->name('agents');
    Route::get('/queue', [QueueController::class, 'index'])->name('queue');
    Route::post('/queue/{id}/dismiss', [QueueController::class, 'dismiss'])->name('queue.dismiss');
    Route::post('/queue/{id}/assign', [QueueController::class, 'assign'])->name('queue.assign');
});
