<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\IngredientInventoryController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\DailyExportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\CashierShiftController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\AuditLogController;
use GuzzleHttp\Psr7\Response;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware('auth:api')->group(function () {
    // Available to any authenticated user, regardless of role.
    Route::get('/exchange-rates', function () {
        return response()->json(['usd_to_khr' => 4100]);
    });
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Admin-only: user management, catalog writes, promotions, inventory, exports.
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::delete('/products/{id}', [ProductController::class, 'destroy']);
        Route::get('/products/{id}/ingredients', [ProductController::class, 'ingredients']);
        Route::put('/products/{id}/ingredients', [ProductController::class, 'syncIngredients']);

        Route::post('/tables', [TableController::class, 'store']);
        Route::put('/tables/{id}', [TableController::class, 'update']);
        Route::delete('/tables/{id}', [TableController::class, 'destroy']);

        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::post('/inventory/restock', [InventoryController::class, 'restock']);
        Route::get('/inventory/history', [InventoryController::class, 'history']);

        Route::get('/ingredients', [IngredientController::class, 'index']);
        Route::post('/ingredients', [IngredientController::class, 'store']);
        Route::put('/ingredients/{id}', [IngredientController::class, 'update']);
        Route::delete('/ingredients/{id}', [IngredientController::class, 'destroy']);
        Route::post('/ingredients/restock', [IngredientInventoryController::class, 'restock']);
        Route::get('/ingredients/history', [IngredientInventoryController::class, 'history']);

        Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
        Route::put('/payment-methods/{id}', [PaymentMethodController::class, 'update']);
        Route::delete('/payment-methods/{id}', [PaymentMethodController::class, 'destroy']);

        Route::post('/promotions', [PromotionController::class, 'store']);
        Route::put('/promotions/{id}', [PromotionController::class, 'update']);
        Route::delete('/promotions/{id}', [PromotionController::class, 'destroy']);

        Route::get('/daily-exports', [DailyExportController::class, 'index']);
        Route::post('/daily-exports/generate', [DailyExportController::class, 'generate']);
        Route::get('/daily-exports/{date}/download', [DailyExportController::class, 'download']);

        Route::put('/settings/low-stock-threshold', [SettingController::class, 'updateLowStockThreshold']);

        Route::put('/cashier-shifts/{id}/review', [CashierShiftController::class, 'review']);
        Route::put('/orders/{id}/refund', [OrderController::class, 'refund']);

        // Profit/COGS/margin exposes supplier cost data - admin only, not cashiers.
        Route::get('/orders/profit-summary', [OrderController::class, 'profitSummary']);

        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::put('/expenses/{id}', [ExpenseController::class, 'update']);
        Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy']);
        Route::get('/expenses/summary', [ExpenseController::class, 'summary']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        // Backups can dump/restore the entire database - throttled tighter
        // than ordinary admin writes since each run is expensive and restore
        // is destructive.
        Route::middleware('throttle:5,1')->group(function () {
            Route::get('/backups', [BackupController::class, 'index']);
            Route::post('/backups/generate', [BackupController::class, 'generate']);
            Route::get('/backups/{id}/download', [BackupController::class, 'download']);
            Route::post('/backups/{id}/restore', [BackupController::class, 'restore']);
        });
    });

    // Shared: admin + cashier. Orders/tables (POS operation) and read-only
    // catalog data cashiers need to populate the POS menu.
    Route::middleware('role:admin,cashier')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
        Route::get('/promotions', [PromotionController::class, 'index']);
        Route::get('/settings/low-stock-threshold', [SettingController::class, 'getLowStockThreshold']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/latest', [OrderController::class, 'latest']);
        Route::get('/orders/sales-by-cashier', [OrderController::class, 'salesByCashier']);
        Route::get('/orders/sales-summary', [OrderController::class, 'salesSummary']);
        Route::get('/orders/top-products', [OrderController::class, 'topProducts']);
        Route::get('/orders/category-sales', [OrderController::class, 'categorySales']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::put('/orders/{id}', [OrderController::class, 'update']);
        Route::post('/orders/{id}/change-table', [OrderController::class, 'changeTable']);
        Route::put('/orders/{id}/cancel', [OrderController::class, 'cancel']);
        Route::post('/orders/{id}/record-receipt-print', [OrderController::class, 'recordReceiptPrint']);

        Route::get('/tables', [TableController::class, 'index']);
        Route::post('/tables/{id}/clear', [TableController::class, 'clear']);
        Route::post('/tables/{id}/move-reservation', [TableController::class, 'moveReservation']);

        Route::get('/cashier-shifts', [CashierShiftController::class, 'index']);
        Route::get('/cashier-shifts/current', [CashierShiftController::class, 'current']);
        Route::get('/cashier-shifts/cash-movements-summary', [CashierShiftController::class, 'cashMovementsSummary']);
        Route::get('/cashier-shifts/{id}', [CashierShiftController::class, 'show']);
        Route::post('/cashier-shifts/open', [CashierShiftController::class, 'open']);
        Route::put('/cashier-shifts/{id}/close', [CashierShiftController::class, 'close']);
        Route::post('/cashier-shifts/{id}/cash-movements', [CashierShiftController::class, 'addCashMovement']);
    });
});
