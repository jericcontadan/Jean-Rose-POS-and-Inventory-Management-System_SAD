<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SalesRecordController;
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

    // Point of Sale screen and its cart (the cart lives in the session until it is paid).
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('/pos/cart/{product}', [PosController::class, 'add'])->name('pos.cart.add');
    Route::post('/pos/cart/{product}/increase', [PosController::class, 'increase'])->name('pos.cart.increase');
    Route::post('/pos/cart/{product}/decrease', [PosController::class, 'decrease'])->name('pos.cart.decrease');
    Route::delete('/pos/cart/{product}', [PosController::class, 'remove'])->name('pos.cart.remove');
    Route::delete('/pos/cart', [PosController::class, 'clear'])->name('pos.cart.clear');

    // Pay Now. block(): two taps from the same session run one after the other, never at the same time.
    Route::post('/pos/pay', [PaymentController::class, 'store'])->name('pos.pay')->block(10, 10);

    // Receipt of a saved sale (a cashier sees only their own; checked in ReceiptController).
    Route::get('/receipts/{sale}', [ReceiptController::class, 'show'])->name('receipts.show');

    // Placeholder until Step 8 builds the Alerts screen (cashier and owner both use it).
    Route::view('/alerts', 'placeholder', ['title' => 'Alerts', 'step' => 8])->name('alerts.index');
});

// OWNER only. can:owner uses the Gate in AppServiceProvider and returns 403 for the cashier.
Route::middleware(['auth', 'can:owner'])->group(function () {
    // Inventory: one row per batch, summary cards, live search.
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

    // Update stock of one batch (RESTOCK) and Update price of one product.
    // block(): two taps on Save run one after the other, never at the same time.
    Route::get('/inventory/{inventory}/stock', [InventoryController::class, 'editStock'])->name('inventory.stock.edit');
    Route::put('/inventory/{inventory}/stock', [InventoryController::class, 'updateStock'])->name('inventory.stock.update')->block(10, 10);
    Route::get('/products/{product}/price', [ProductController::class, 'editPrice'])->name('products.price.edit');
    Route::put('/products/{product}/price', [ProductController::class, 'updatePrice'])->name('products.price.update')->block(10, 10);

    // Sales Records: date filter, sales grouped by receipt, daily summary, Close summary.
    Route::get('/sales', [SalesRecordController::class, 'index'])->name('sales.index');

    // Void a Sale. block(): two taps on "Confirm void" run one after the other, never at the same time.
    Route::post('/sales/{sale}/void', [SalesRecordController::class, 'void'])->name('sales.void')->block(10, 10);

    // Audit Log: READ ONLY. GET only, on purpose: there is no route to edit or delete audit rows (T18).
    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit.index');
});
