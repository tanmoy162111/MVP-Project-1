<?php

use Illuminate\Support\Facades\Route;
use App\Modules\User\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes — v1
|--------------------------------------------------------------------------
| All routes are prefixed with /api/v1 (configured in bootstrap/app.php)
| Authentication: Laravel Sanctum (Bearer token)
| Response format: Standard ApiResponse envelope
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ── HEALTH CHECK ──────────────────────────────────────────────────────────
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'message' => 'Electronics Platform API is running.',
            'version' => '1.0.0',
            'timestamp' => now()->toISOString(),
        ]);
    });

    // ── AUTH (public) ─────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login',    [AuthController::class, 'login']);
    });

    // ── AUTHENTICATED ROUTES ──────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::prefix('auth')->group(function () {
            Route::post('/logout',  [AuthController::class, 'logout']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
            Route::get('/me',       [AuthController::class, 'me']);
        });

        // ── USER MANAGEMENT (admin) ─────────────────────────────────────────
        // Phase 1 skeleton — controllers wired in Phase 2+
        Route::prefix('users')->middleware('permission:users.view')->group(function () {
            Route::get('/',    fn() => response()->json(['message' => 'User listing — Phase 2']));
            Route::get('/{id}',fn() => response()->json(['message' => 'User detail — Phase 2']));
        });

        // ── VENDORS ──────────────────────────────────────────────────────────
        Route::prefix('vendors')->group(function () {
            Route::get('/', fn() => response()->json(['message' => 'Vendor listing — Phase 2']));
            Route::middleware('permission:vendors.approve')->group(function () {
                Route::post('/{id}/approve', fn($id) => response()->json(['message' => "Vendor $id approve — Phase 2"]));
                Route::post('/{id}/reject',  fn($id) => response()->json(['message' => "Vendor $id reject — Phase 2"]));
            });
        });

        // ── PRODUCTS ─────────────────────────────────────────────────────────
        Route::prefix('products')->group(function () {
            Route::get('/', fn() => response()->json(['message' => 'Product listing — Phase 2']));
            Route::post('/', fn() => response()->json(['message' => 'Create product — Phase 2']));
            Route::get('/{id}', fn($id) => response()->json(['message' => "Product $id — Phase 2"]));
        });

        // ── ORDERS ───────────────────────────────────────────────────────────
        Route::prefix('orders')->group(function () {
            Route::get('/', fn() => response()->json(['message' => 'Order listing — Phase 3']));
            Route::post('/', fn() => response()->json(['message' => 'Place order — Phase 3']));
            Route::get('/{id}', fn($id) => response()->json(['message' => "Order $id — Phase 3"]));
        });

        // ── PRICING ──────────────────────────────────────────────────────────
        Route::prefix('pricing')->middleware('permission:pricing.view')->group(function () {
            Route::get('/calculate', fn() => response()->json(['message' => 'Price calculation — Phase 4']));
            Route::get('/rules',     fn() => response()->json(['message' => 'Pricing rules — Phase 4']));
        });

        // ── INVOICES ─────────────────────────────────────────────────────────
        Route::prefix('invoices')->middleware('permission:invoices.view')->group(function () {
            Route::get('/', fn() => response()->json(['message' => 'Invoice listing — Phase 5']));
            Route::get('/{id}', fn($id) => response()->json(['message' => "Invoice $id — Phase 5"]));
        });

        // ── REPORTS ──────────────────────────────────────────────────────────
        Route::prefix('reports')->middleware('permission:reports.view')->group(function () {
            Route::get('/sales',   fn() => response()->json(['message' => 'Sales report — Phase 7']));
            Route::get('/vendors', fn() => response()->json(['message' => 'Vendor report — Phase 7']));
        });
    });
});
