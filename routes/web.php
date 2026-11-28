<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SalesRecordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public health check (no login needed).
Route::get('/health', HealthController::class)->name('health');

// Guests only (a logged-in user is sent to their home page instead).
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');

    // Forgot and Reset Password by Gmail link (screen 2). block(): a double tap runs one after the other.
    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->name('password.email')->block(10, 10);
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update')->block(10, 10);
});

// Any logged-in user: OWNER or CASHIER.
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    // "/" opens the user's home page: Dashboard for the owner, POS for the cashier.
    Route::get('/', fn (Request $request) => redirect()->route($request->user()->homeRoute()));

    // Receipt of a saved sale (a cashier sees only their own; checked in ReceiptController).
    Route::get('/receipts/{sale}', [ReceiptController::class, 'show'])->name('receipts.show');

    // Alerts: the open stock and expiry alerts (cashier and owner both use it).
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
});

// CASHIER only (Team 4's DFD: the cashier processes sales). can:cashier returns 403 for the owner.
Route::middleware(['auth', 'can:cashier'])->group(function () {
    // Point of Sale screen and its cart (the cart lives in the session until it is paid).
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('/pos/cart/{product}', [PosController::class, 'add'])->name('pos.cart.add');
    Route::post('/pos/cart/{product}/increase', [PosController::class, 'increase'])->name('pos.cart.increase');
    Route::post('/pos/cart/{product}/decrease', [PosController::class, 'decrease'])->name('pos.cart.decrease');
    Route::delete('/pos/cart/{product}', [PosController::class, 'remove'])->name('pos.cart.remove');
    Route::delete('/pos/cart', [PosController::class, 'clear'])->name('pos.cart.clear');

    // Pay Now. block(): two taps from the same session run one after the other, never at the same time.
    Route::post('/pos/pay', [PaymentController::class, 'store'])->name('pos.pay')->block(10, 10);
});

// OWNER only. can:owner uses the Gate in AppServiceProvider and returns 403 for the cashier.
Route::middleware(['auth', 'can:owner'])->group(function () {
    // Dashboard: the owner's home page (today's sales, month total, voids, Senior/PWD, alerts, latest sales).
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Inventory: one row per batch, summary cards, live search.
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

    // Update stock of one batch (RESTOCK) and Update price of one product.
    // block(): two taps on Save run one after the other, never at the same time.
    Route::get('/inventory/{inventory}/stock', [InventoryController::class, 'editStock'])->name('inventory.stock.edit');
    Route::put('/inventory/{inventory}/stock', [InventoryController::class, 'updateStock'])->name('inventory.stock.update')->block(10, 10);
    // Remove stock of one batch (expired or damaged pieces), saved as ADJUSTMENT in inventory history.
    Route::get('/inventory/{inventory}/remove', [InventoryController::class, 'editRemove'])->name('inventory.remove.edit');
    Route::put('/inventory/{inventory}/remove', [InventoryController::class, 'updateRemove'])->name('inventory.remove.update')->block(10, 10);
    Route::get('/products/{product}/price', [ProductController::class, 'editPrice'])->name('products.price.edit');
    Route::put('/products/{product}/price', [ProductController::class, 'updatePrice'])->name('products.price.update')->block(10, 10);

    // Add or Edit Product (screen 12): add a product with its first batch, edit product fields,
    // add a new batch (new delivery), and the trash on an Inventory row (delete one unused batch).
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store')->block(10, 10);
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update')->block(10, 10);
    Route::post('/products/{product}/batches', [ProductController::class, 'storeBatch'])->name('products.batches.store')->block(10, 10);
    Route::delete('/inventory/{inventory}', [InventoryController::class, 'destroy'])->name('inventory.destroy')->block(10, 10);

    // Sales Records: date filter, sales grouped by receipt, daily summary, Close summary.
    Route::get('/sales', [SalesRecordController::class, 'index'])->name('sales.index');

    // Void a Sale. block(): two taps on "Confirm void" run one after the other, never at the same time.
    Route::post('/sales/{sale}/void', [SalesRecordController::class, 'void'])->name('sales.void')->block(10, 10);

    // Void ONE item of a sale (the rest of the sale stays paid). Same block() protection against double taps.
    Route::post('/sales/items/{item}/void', [SalesRecordController::class, 'voidItem'])->name('sales.items.void')->block(10, 10);

    // Audit Log: READ ONLY. GET only, on purpose: there is no route to edit or delete audit rows (T18).
    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit.index');

    // Excel export (the Export card on the Audit Log page): Sales, Inventory or Audit log as .xlsx.
    Route::get('/exports/download', [ExportController::class, 'download'])->name('exports.download');
});
