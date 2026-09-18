<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutQuoteController;
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
});
