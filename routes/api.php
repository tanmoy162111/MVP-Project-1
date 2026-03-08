<?php

use Illuminate\Support\Facades\Route;
use App\Modules\User\Controllers\AuthController;
use App\Modules\Vendor\Controllers\VendorController;
use App\Modules\Product\Controllers\ProductController;
use App\Modules\Product\Controllers\CategoryController;
use App\Modules\Product\Controllers\BrandController;
use App\Modules\Order\Controllers\OrderController;
use App\Modules\Pricing\Controllers\PricingController;
use App\Modules\CRM\Controllers\CrmController;

Route::prefix('v1')->group(function () {

    // ── HEALTH ────────────────────────────────────────────────────────────────
    Route::get('/health', fn() => response()->json([
        'success' => true, 'message' => 'Electronics Platform API',
        'version' => '1.0.0', 'timestamp' => now()->toISOString(),
    ]));

    // ── PUBLIC: AUTH ──────────────────────────────────────────────────────────
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login',    [AuthController::class, 'login']);

    // ── PUBLIC: STOREFRONT ────────────────────────────────────────────────────
    Route::get('/categories',      [CategoryController::class, 'index']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);
    Route::get('/brands',          [BrandController::class,    'index']);
    Route::get('/products',        [ProductController::class,  'index']);
    Route::get('/products/{id}',   [ProductController::class,  'show']);
    Route::get('/vendors/{id}',    [VendorController::class,   'show']);

    // ── PUBLIC: PRICING (optional auth for personalised prices) ───────────────
    Route::get('/pricing/calculate',        [PricingController::class, 'calculate']);
    Route::post('/pricing/calculate-batch', [PricingController::class, 'calculateBatch']);

    // ── AUTHENTICATED ─────────────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('/auth/logout',  [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh']);
        Route::get('/auth/me',       [AuthController::class, 'me']);

        // Vendor self-service
        Route::post('/vendor/register', [VendorController::class, 'register']);
        Route::middleware('role:vendor|admin|super_admin')->group(function () {
            Route::post('/vendor/products',        [ProductController::class, 'store']);
            Route::put('/vendor/products/{id}',    [ProductController::class, 'update']);
            Route::delete('/vendor/products/{id}', [ProductController::class, 'destroy']);
        });

        // Orders
        Route::get('/orders',                    [OrderController::class, 'index']);
        Route::post('/orders',                   [OrderController::class, 'store']);
        Route::get('/orders/{id}',               [OrderController::class, 'show']);
        Route::post('/orders/{id}/cancel',       [OrderController::class, 'cancel']);
        Route::get('/orders/{id}/next-statuses', [OrderController::class, 'nextStatuses']);

        // ── ADMIN ─────────────────────────────────────────────────────────────
        Route::prefix('admin')->middleware('role:admin|super_admin')->group(function () {

            // Vendor management
            Route::get('/vendors',                [VendorController::class,  'index']);
            Route::put('/vendors/{id}',           [VendorController::class,  'update']);
            Route::post('/vendors/{id}/approve',  [VendorController::class,  'approve']);
            Route::post('/vendors/{id}/reject',   [VendorController::class,  'reject']);
            Route::post('/vendors/{id}/suspend',  [VendorController::class,  'suspend']);

            // Product management
            Route::get('/products',               [ProductController::class, 'adminIndex']);
            Route::post('/products/{id}/approve', [ProductController::class, 'approve']);
            Route::post('/products/{id}/reject',  [ProductController::class, 'reject']);

            // Categories & Brands
            Route::post('/categories',            [CategoryController::class, 'store']);
            Route::put('/categories/{id}',        [CategoryController::class, 'update']);
            Route::delete('/categories/{id}',     [CategoryController::class, 'destroy']);
            Route::post('/brands',                [BrandController::class,    'store']);
            Route::put('/brands/{id}',            [BrandController::class,    'update']);
            Route::delete('/brands/{id}',         [BrandController::class,    'destroy']);

            // Order management
            Route::post('/orders/{id}/transition',[OrderController::class,   'transition']);

            // ── PRICING (admin + pricing_manager) ─────────────────────────────
            Route::prefix('pricing')->middleware('role:admin|super_admin|pricing_manager')->group(function () {
                // OPIS status & refresh
                Route::get('/opis-status',         [PricingController::class, 'opisStatus']);
                Route::post('/opis-refresh',       [PricingController::class, 'opisRefresh']);

                // Pricing rules CRUD
                Route::get('/rules',               [PricingController::class, 'indexRules']);
                Route::post('/rules',              [PricingController::class, 'storeRule']);
                Route::put('/rules/{id}',          [PricingController::class, 'updateRule']);
                Route::delete('/rules/{id}',       [PricingController::class, 'destroyRule']);

                // Customer contracts CRUD
                Route::get('/contracts',           [PricingController::class, 'indexContracts']);
                Route::post('/contracts',          [PricingController::class, 'storeContract']);
                Route::put('/contracts/{id}',      [PricingController::class, 'updateContract']);
                Route::get('/contracts/{id}',      [PricingController::class, 'showContract']);
            });

            // ── CRM: ADMIN ──────────────────────────────────────────────────────
            // Customer tier management
            Route::post('/customers/{id}/evaluate-tier',                [CrmController::class, 'evaluateTier']);
            Route::post('/customers/evaluate-all-tiers',                [CrmController::class, 'evaluateAllTiers']);

            // Communication logs
            Route::get('/customers/{id}/communications',                [CrmController::class, 'communicationIndex']);
            Route::post('/customers/{id}/communications',               [CrmController::class, 'communicationStore']);
            Route::patch('/customers/{customerId}/communications/{logId}/pin', [CrmController::class, 'communicationTogglePin']);

            // Coupons
            Route::get('/coupons',                                       [CrmController::class, 'couponIndex']);
            Route::post('/coupons',                                      [CrmController::class, 'couponStore']);
            Route::put('/coupons/{id}',                                  [CrmController::class, 'couponUpdate']);
            Route::delete('/coupons/{id}',                               [CrmController::class, 'couponDestroy']);

            // Payouts
            Route::get('/payouts/preview',                               [CrmController::class, 'payoutPreviewAll']);
            Route::get('/payouts',                                       [CrmController::class, 'payoutIndex']);
            Route::post('/payouts',                                      [CrmController::class, 'payoutCreate']);
            Route::post('/payouts/{id}/process',                         [CrmController::class, 'payoutProcess']);
            Route::post('/payouts/{id}/complete',                        [CrmController::class, 'payoutComplete']);
        });

        // ── PHASE 5 INVOICE STUB (replaced by real routes above) ─────────────
        Route::get('/invoices', fn() => response()->json(['message' => 'Invoice system — Phase 5']));

        // ── CRM: ACCOUNT ────────────────────────────────────────────────────────
        Route::get('/account/tier',                      [CrmController::class, 'myTier']);
        Route::get('/account/wishlist',                  [CrmController::class, 'wishlistIndex']);
        Route::post('/account/wishlist',                 [CrmController::class, 'wishlistAdd']);
        Route::delete('/account/wishlist/{id}',          [CrmController::class, 'wishlistRemove']);
        Route::get('/account/notifications',             [CrmController::class, 'notificationIndex']);
        Route::post('/account/notifications/read-all',   [CrmController::class, 'notificationReadAll']);
        Route::patch('/account/notifications/{id}/read', [CrmController::class, 'notificationRead']);

        // ── COUPONS ─────────────────────────────────────────────────────────────
        Route::post('/coupons/validate',                 [CrmController::class, 'couponValidate']);

        // ── VENDOR: PAYOUTS ─────────────────────────────────────────────────────
        Route::middleware('role:vendor|admin|super_admin')->group(function () {
            Route::get('/vendor/payouts',                [CrmController::class, 'vendorPayoutHistory']);
        });

        // ── REPORTING (Phase 7) ───────────────────────────────────────────────
        // Vendor self-service
        Route::middleware('role:vendor|admin|super_admin')->group(function () {
            Route::get('/vendor/reports/performance', [\App\Modules\Reporting\Controllers\ReportingController::class, 'vendorSelfReport']);
        });

        // Admin reports
        Route::prefix('admin/reports')->middleware('role:admin|super_admin|finance_manager|pricing_manager')->group(function () {
            Route::get('/dashboard',     [\App\Modules\Reporting\Controllers\ReportingController::class, 'dashboard']);
            Route::get('/sales',         [\App\Modules\Reporting\Controllers\ReportingController::class, 'salesSummary']);
            Route::get('/revenue-trend', [\App\Modules\Reporting\Controllers\ReportingController::class, 'revenueTrend']);
            Route::get('/vendors',       [\App\Modules\Reporting\Controllers\ReportingController::class, 'vendorPerformance']);
            Route::get('/products',      [\App\Modules\Reporting\Controllers\ReportingController::class, 'topProducts']);
            Route::get('/low-stock',     [\App\Modules\Reporting\Controllers\ReportingController::class, 'lowStock']);
            Route::get('/categories',    [\App\Modules\Reporting\Controllers\ReportingController::class, 'categoryBreakdown']);
            Route::get('/customers',     [\App\Modules\Reporting\Controllers\ReportingController::class, 'customerAnalytics']);
            Route::get('/export',        [\App\Modules\Reporting\Controllers\ReportingController::class, 'export']);
        });
    });
});
