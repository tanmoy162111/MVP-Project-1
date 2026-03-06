<?php

use Illuminate\Support\Facades\Route;
use App\Modules\User\Controllers\AuthController;
use App\Modules\Vendor\Controllers\VendorController;
use App\Modules\Product\Controllers\ProductController;
use App\Modules\Product\Controllers\CategoryController;
use App\Modules\Product\Controllers\BrandController;

Route::prefix('v1')->group(function () {

    Route::get('/health', function () {
        return response()->json(['success' => true, 'message' => 'Electronics Platform API', 'version' => '1.0.0', 'timestamp' => now()->toISOString()]);
    });

    // Public auth
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login',    [AuthController::class, 'login']);
    });

    // Public storefront
    Route::get('/categories',      [CategoryController::class, 'index']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);
    Route::get('/brands',          [BrandController::class,    'index']);
    Route::get('/products',        [ProductController::class,  'index']);
    Route::get('/products/{id}',   [ProductController::class,  'show']);
    Route::get('/vendors/{id}',    [VendorController::class,   'show']);

    // Authenticated
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('/auth/logout',  [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh']);
        Route::get('/auth/me',       [AuthController::class, 'me']);

        // Vendor self-service
        Route::post('/vendor/register', [VendorController::class, 'register']);

        Route::middleware('role:vendor|admin|super_admin')->group(function () {
            Route::get('/vendor/products',        [ProductController::class, 'adminIndex']);
            Route::post('/vendor/products',       [ProductController::class, 'store']);
            Route::put('/vendor/products/{id}',   [ProductController::class, 'update']);
            Route::delete('/vendor/products/{id}',[ProductController::class, 'destroy']);
        });

        // Admin
        Route::prefix('admin')->middleware('role:admin|super_admin')->group(function () {
            Route::get('/vendors',               [VendorController::class,  'index']);
            Route::put('/vendors/{id}',          [VendorController::class,  'update']);
            Route::post('/vendors/{id}/approve', [VendorController::class,  'approve']);
            Route::post('/vendors/{id}/reject',  [VendorController::class,  'reject']);
            Route::post('/vendors/{id}/suspend', [VendorController::class,  'suspend']);

            Route::get('/products',              [ProductController::class, 'adminIndex']);
            Route::post('/products/{id}/approve',[ProductController::class, 'approve']);
            Route::post('/products/{id}/reject', [ProductController::class, 'reject']);

            Route::post('/categories',           [CategoryController::class,'store']);
            Route::put('/categories/{id}',       [CategoryController::class,'update']);
            Route::delete('/categories/{id}',    [CategoryController::class,'destroy']);

            Route::post('/brands',               [BrandController::class,   'store']);
            Route::put('/brands/{id}',           [BrandController::class,   'update']);
            Route::delete('/brands/{id}',        [BrandController::class,   'destroy']);
        });

        // Phase 3+ stubs
        Route::get('/orders',    fn() => response()->json(['message' => 'Phase 3']));
        Route::post('/orders',   fn() => response()->json(['message' => 'Phase 3']));
        Route::get('/invoices',  fn() => response()->json(['message' => 'Phase 5']));
        Route::get('/reports/sales', fn() => response()->json(['message' => 'Phase 7']));
    });
});
