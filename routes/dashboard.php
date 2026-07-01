<?php

declare(strict_types=1);

use JobWarden\Http\Livewire\Batches;
use JobWarden\Http\Livewire\Jobs;
use JobWarden\Http\Livewire\JobShow;
use JobWarden\Http\Livewire\Overview;
use JobWarden\Http\Livewire\Schedules;
use JobWarden\Http\Livewire\Workers;
use Illuminate\Support\Facades\Route;

// Livewire operator dashboard. Mounted under jobwarden.dashboard.prefix, gated.

Route::get('/', Overview::class)->name('jobwarden.overview');
Route::get('/jobs', Jobs::class)->name('jobwarden.jobs');
Route::get('/jobs/{job}', JobShow::class)->name('jobwarden.jobs.show');
Route::get('/batches', Batches::class)->name('jobwarden.batches');
Route::get('/schedules', Schedules::class)->name('jobwarden.schedules');
Route::get('/workers', Workers::class)->name('jobwarden.workers');
