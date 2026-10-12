<?php

use App\Http\Controllers\GateController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProjectController;
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
    Route::post('/projects/{slug}/inputs/{id}/resolve', [ProjectController::class, 'resolveInput'])->name('projects.inputs.resolve');
    Route::post('/projects/{slug}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::post('/projects/{slug}/unarchive', [ProjectController::class, 'unarchive'])->name('projects.unarchive');
    // r0 screens removed in r1.
    Route::redirect('/ops', '/');
    Route::redirect('/agents', '/');
    Route::redirect('/queue', '/');
});
