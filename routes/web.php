<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Public health check (no login needed).
Route::get('/health', HealthController::class)->name('health');

// Guests only (a logged-in user is sent to /pos instead).
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

// Any logged-in user: OWNER or CASHIER.
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/pos');

    // Placeholder until Step 2 builds the POS screen.
    Route::view('/pos', 'placeholder', ['title' => 'Point of Sale', 'step' => 2])->name('pos.index');

    // Placeholder until Step 8 builds the Alerts screen (cashier and owner both use it).
    Route::view('/alerts', 'placeholder', ['title' => 'Alerts', 'step' => 8])->name('alerts.index');
});

// OWNER only. can:owner uses the Gate in AppServiceProvider and returns 403 for the cashier.
Route::middleware(['auth', 'can:owner'])->group(function () {
    // Placeholders until Steps 5, 4 and 6 build these screens.
    Route::view('/sales', 'placeholder', ['title' => 'Sales Records', 'step' => 5])->name('sales.index');
    Route::view('/inventory', 'placeholder', ['title' => 'Inventory', 'step' => 4])->name('inventory.index');
    Route::view('/audit-log', 'placeholder', ['title' => 'Audit Log', 'step' => 6])->name('audit.index');
});
