<?php

use App\Http\Controllers\Api\V1\{MerchantAnalyticsController, ReduceStockController, AtRiskCustomersController};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('api')->group(function () {
    Route::prefix('merchants')->group(function () {
        Route::get('/{merchantId}/analytics', MerchantAnalyticsController::class)
            ->name('merchants.analytics');

        Route::get('/{merchantId}/at-risk-customers', AtRiskCustomersController::class)
            ->name('merchants.at-risk-customers');
    });

    Route::prefix('products')->group(function () {
        Route::post('/{productId}/reduce-stock', ReduceStockController::class)
            ->name('products.reduce-stock');
    });
});

Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'Endpoint not found',
    ], 404);
});

