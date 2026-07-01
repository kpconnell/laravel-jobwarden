<?php

declare(strict_types=1);

use JobWarden\Http\Controllers\BatchesController;
use JobWarden\Http\Controllers\JobsController;
use JobWarden\Http\Controllers\SchedulesController;
use JobWarden\Http\Controllers\StatsController;
use JobWarden\Http\Controllers\WorkersController;
use Illuminate\Support\Facades\Route;

// Operator API. Mounted under jobwarden.api.prefix, behind the Authorize gate.

Route::get('stats', StatsController::class);

Route::post('jobs', [JobsController::class, 'store']);
Route::get('jobs', [JobsController::class, 'index']);
Route::get('jobs/{job}', [JobsController::class, 'show']);
Route::get('jobs/{job}/logs', [JobsController::class, 'logs']);
Route::post('jobs/{job}/cancel', [JobsController::class, 'cancel']);
Route::post('jobs/{job}/stop', [JobsController::class, 'stop']);
Route::post('jobs/{job}/retry', [JobsController::class, 'retry']);
Route::post('jobs/{job}/restart', [JobsController::class, 'restart']);

Route::get('batches', [BatchesController::class, 'index']);
Route::get('batches/{batch}', [BatchesController::class, 'show']);
Route::post('batches/{batch}/cancel', [BatchesController::class, 'cancel']);

Route::get('schedules', [SchedulesController::class, 'index']);
Route::post('schedules', [SchedulesController::class, 'store']);
Route::get('schedules/{schedule}', [SchedulesController::class, 'show']);
Route::patch('schedules/{schedule}', [SchedulesController::class, 'update']);
Route::delete('schedules/{schedule}', [SchedulesController::class, 'destroy']);
Route::post('schedules/{schedule}/run', [SchedulesController::class, 'run']);

Route::get('workers', [WorkersController::class, 'index']);
