<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Public health check (no login needed).
Route::get('/health', HealthController::class)->name('health');

// Temporary home page to preview the layout. Step 1 changes this to redirect to /login.
Route::view('/', 'home')->name('home');
