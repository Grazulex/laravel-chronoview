<?php

declare(strict_types=1);

use Grazulex\ChronoView\Http\Controllers\ActionController;
use Grazulex\ChronoView\Http\Controllers\AssetController;
use Grazulex\ChronoView\Http\Controllers\DashboardController;
use Grazulex\ChronoView\Http\Controllers\RunController;
use Grazulex\ChronoView\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('assets/{file}', AssetController::class)
    ->where('file', '[a-zA-Z0-9._-]+')
    ->name('asset');

Route::middleware(config('chronoview.middleware', ['web', 'chronoview.auth']))->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');

    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
    Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');

    Route::post('tasks/{task}/run', [ActionController::class, 'run'])->name('tasks.run');
    Route::post('tasks/{task}/pause', [ActionController::class, 'pause'])->name('tasks.pause');
    Route::post('tasks/{task}/resume', [ActionController::class, 'resume'])->name('tasks.resume');
});
