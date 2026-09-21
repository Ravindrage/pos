<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Public
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Authenticated + tenant-scoped
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::apiResource('categories', CategoryController::class)->except(['show']);

        Route::apiResource('products', ProductController::class);
        Route::post('/products/{product}/adjust-stock', [ProductController::class, 'adjustStock']);

        Route::apiResource('customers', CustomerController::class);

        Route::apiResource('sales', SaleController::class)->only(['index', 'show', 'store']);
        Route::post('/sales/{sale}/void', [SaleController::class, 'void']);
    });
});
