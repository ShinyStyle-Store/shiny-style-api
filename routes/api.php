<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminProfileController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutQuoteController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ShippingAreaController;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(SetApiLocale::class)->group(function (): void {
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/featured', [ProductController::class, 'featured']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);
    Route::get('shipping-areas', [ShippingAreaController::class, 'index']);
    Route::post('checkout/quote', [CheckoutQuoteController::class, 'store']);
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:guest-orders');

    Route::prefix('admin/auth')->group(function (): void {
        Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login');

        Route::middleware(['auth:sanctum', 'admin.access'])->group(function (): void {
            Route::get('me', [AdminAuthController::class, 'me']);
            Route::post('logout', [AdminAuthController::class, 'logout']);
            Route::post('logout-all', [AdminAuthController::class, 'logoutAll']);
        });
    });

    Route::prefix('admin')->middleware(['auth:sanctum', 'admin.access'])->group(function (): void {
        Route::get('orders', [AdminOrderController::class, 'index']);
        Route::get('orders/{public_id}', [AdminOrderController::class, 'show'])
            ->where('public_id', '[0-9A-HJKMNP-TV-Z]{26}');
        Route::get('profile', [AdminProfileController::class, 'show']);
        Route::patch('profile', [AdminProfileController::class, 'update']);
        Route::put('profile/password', [AdminProfileController::class, 'updatePassword']);
    });
});
