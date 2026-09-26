<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminBannerController;
use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminMediaController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminProfileController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AdminProductOptionController;
use App\Http\Controllers\Api\AdminSellableItemController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutQuoteController;
use App\Http\Controllers\Api\HomeProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymobWebhookController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentReturnController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductSectionController;
use App\Http\Controllers\Api\AdminProductReviewImageController;
use App\Http\Controllers\Api\ShippingAreaController;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(SetApiLocale::class)->group(function (): void {
    Route::get('products', [ProductController::class, 'index']);
    Route::get('home/products', [HomeProductController::class, 'products']);
    Route::get('products/sections/{section}', [ProductSectionController::class, 'index'])
        ->whereIn('section', ['featured', 'newest', 'best-selling', 'offers']);
    Route::get('products/{slug}/review-images', [AdminProductReviewImageController::class, 'publicIndex']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);
    Route::get('banners', [BannerController::class, 'index']);
    Route::get('shipping-areas', [ShippingAreaController::class, 'index']);
    Route::post('checkout/quote', [CheckoutQuoteController::class, 'store']);
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:guest-orders');
    Route::post('payments/paymob/webhook', [PaymobWebhookController::class, 'handle'])
        ->middleware('throttle:paymob-webhook');
    Route::post('orders/{public_id}/payments', [PaymentController::class, 'initiate'])
        ->middleware(['throttle:payment-initiation'])
        ->name('orders.payments.initiate');
    Route::get('orders/{public_id}/payment-status', [PaymentController::class, 'status'])
        ->middleware(['throttle:payment-status'])
        ->name('orders.payments.status');
    Route::get('payments/return', [PaymentReturnController::class, 'show'])
        ->middleware(['throttle:payment-status'])
        ->name('payments.return');

    Route::prefix('admin/auth')->group(function (): void {
        Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login');

        Route::middleware(['auth:sanctum', 'admin.access'])->group(function (): void {
            Route::get('me', [AdminAuthController::class, 'me']);
            Route::post('logout', [AdminAuthController::class, 'logout']);
            Route::post('logout-all', [AdminAuthController::class, 'logoutAll']);
        });
    });

    Route::prefix('admin')->middleware(['auth:sanctum', 'admin.access'])->group(function (): void {
        Route::get('categories', [AdminCategoryController::class, 'index']);
        Route::get('categories/archived', [AdminCategoryController::class, 'archived']);
        Route::post('categories/{category}/restore', [AdminCategoryController::class, 'restore'])->whereNumber('category');
        Route::get('categories/{category}', [AdminCategoryController::class, 'show'])->whereNumber('category');
        Route::post('categories', [AdminCategoryController::class, 'store']);
        Route::patch('categories/{category}', [AdminCategoryController::class, 'update'])->whereNumber('category');
        Route::delete('categories/{category}', [AdminCategoryController::class, 'destroy'])->whereNumber('category');
        Route::get('orders', [AdminOrderController::class, 'index']);
        Route::get('orders/{public_id}', [AdminOrderController::class, 'show'])
            ->where('public_id', '[0-9A-HJKMNP-TV-Z]{26}');
        Route::post('orders/{public_id}/confirm', [AdminOrderController::class, 'confirm'])
            ->where('public_id', '[0-9A-HJKMNP-TV-Z]{26}');
        Route::post('orders/{public_id}/prepare', [AdminOrderController::class, 'prepare'])
            ->where('public_id', '[0-9A-HJKMNP-TV-Z]{26}');
        Route::post('orders/{public_id}/ship', [AdminOrderController::class, 'ship'])
            ->where('public_id', '[0-9A-HJKMNP-TV-Z]{26}');
        Route::post('orders/{public_id}/deliver', [AdminOrderController::class, 'deliver'])
            ->where('public_id', '[0-9A-HJKMNP-TV-Z]{26}');
        Route::post('orders/{public_id}/cancel', [AdminOrderController::class, 'cancel'])
            ->where('public_id', '[0-9A-HJKMNP-TV-Z]{26}');
        Route::patch('orders/{public_id}/contact-status', [AdminOrderController::class, 'updateContactStatus'])
            ->where('public_id', '[0-9A-HJKMNP-TV-Z]{26}');
        Route::get('profile', [AdminProfileController::class, 'show']);
        Route::patch('profile', [AdminProfileController::class, 'update']);
        Route::get('banners', [AdminBannerController::class, 'index']);
        Route::post('banners', [AdminBannerController::class, 'store']);
        Route::get('banners/{banner}', [AdminBannerController::class, 'show'])->whereNumber('banner');
        Route::patch('banners/{banner}', [AdminBannerController::class, 'update'])->whereNumber('banner');
        Route::post('banners/{banner}/image', [AdminBannerController::class, 'image'])->whereNumber('banner');
        Route::delete('banners/{banner}', [AdminBannerController::class, 'destroy'])->whereNumber('banner');
        Route::put('profile/password', [AdminProfileController::class, 'updatePassword']);

        Route::get('products', [AdminProductController::class, 'index']);
        Route::post('products', [AdminProductController::class, 'store']);
        Route::get('products/{product}', [AdminProductController::class, 'show'])->whereNumber('product');
        Route::patch('products/{product}', [AdminProductController::class, 'update'])->whereNumber('product');
        Route::post('products/{product}/archive', [AdminProductController::class, 'archive'])->whereNumber('product');
        Route::post('products/{product}/restore', [AdminProductController::class, 'restore'])->whereNumber('product');

        Route::get('products/{product}/review-images/archived', [AdminProductReviewImageController::class, 'archived'])->whereNumber('product');
        Route::patch('products/{product}/review-images/reorder', [AdminProductReviewImageController::class, 'reorder'])->whereNumber('product');
        Route::get('products/{product}/review-images', [AdminProductReviewImageController::class, 'index'])->whereNumber('product');
        Route::post('products/{product}/review-images/bulk', [AdminProductReviewImageController::class, 'bulk'])->whereNumber('product');
        Route::post('products/{product}/review-images', [AdminProductReviewImageController::class, 'store'])->whereNumber('product');
        Route::match(['patch', 'post'], 'products/{product}/review-images/{reviewImage}', [AdminProductReviewImageController::class, 'update'])
            ->whereNumber('product')->whereNumber('reviewImage');
        Route::post('products/{product}/review-images/{reviewImage}/restore', [AdminProductReviewImageController::class, 'restore'])
            ->whereNumber('product')->whereNumber('reviewImage');
        Route::delete('products/{product}/review-images/{reviewImage}', [AdminProductReviewImageController::class, 'destroy'])
            ->whereNumber('product')->whereNumber('reviewImage');

        Route::get('products/{product}/options', [AdminProductOptionController::class, 'index'])->whereNumber('product');
        Route::post('products/{product}/options', [AdminProductOptionController::class, 'store'])->whereNumber('product');
        Route::get('products/{product}/options/{option}', [AdminProductOptionController::class, 'show'])
            ->whereNumber('product')->whereNumber('option');
        Route::patch('products/{product}/options/{option}', [AdminProductOptionController::class, 'update'])
            ->whereNumber('product')->whereNumber('option');
        Route::post('products/{product}/options/{option}/archive', [AdminProductOptionController::class, 'archive'])
            ->whereNumber('product')->whereNumber('option');
        Route::post('products/{product}/options/{option}/restore', [AdminProductOptionController::class, 'restore'])
            ->whereNumber('product')->whereNumber('option');
        Route::get('products/{product}/options/{option}/values', [AdminProductOptionController::class, 'values'])
            ->whereNumber('product')->whereNumber('option');
        Route::post('products/{product}/options/{option}/values', [AdminProductOptionController::class, 'storeValue'])
            ->whereNumber('product')->whereNumber('option');
        Route::get('products/{product}/options/{option}/values/{value}', [AdminProductOptionController::class, 'showValue'])
            ->whereNumber('product')->whereNumber('option')->whereNumber('value');
        Route::patch('products/{product}/options/{option}/values/{value}', [AdminProductOptionController::class, 'updateValue'])
            ->whereNumber('product')->whereNumber('option')->whereNumber('value');
        Route::post('products/{product}/options/{option}/values/{value}/archive', [AdminProductOptionController::class, 'archiveValue'])
            ->whereNumber('product')->whereNumber('option')->whereNumber('value');
        Route::post('products/{product}/options/{option}/values/{value}/restore', [AdminProductOptionController::class, 'restoreValue'])
            ->whereNumber('product')->whereNumber('option')->whereNumber('value');

        Route::get('products/{product}/sellable-items', [AdminSellableItemController::class, 'index'])->whereNumber('product');
        Route::post('products/{product}/sellable-items', [AdminSellableItemController::class, 'store'])->whereNumber('product');
        Route::get('products/{product}/sellable-items/{variant}', [AdminSellableItemController::class, 'show'])
            ->whereNumber('product')->whereNumber('variant');
        Route::patch('products/{product}/sellable-items/{variant}', [AdminSellableItemController::class, 'update'])
            ->whereNumber('product')->whereNumber('variant');
        Route::post('products/{product}/sellable-items/{variant}/archive', [AdminSellableItemController::class, 'archive'])
            ->whereNumber('product')->whereNumber('variant');
        Route::post('products/{product}/sellable-items/{variant}/restore', [AdminSellableItemController::class, 'restore'])
            ->whereNumber('product')->whereNumber('variant');

        Route::get('products/{product}/media', [AdminMediaController::class, 'productIndex'])->whereNumber('product');
        Route::post('products/{product}/media', [AdminMediaController::class, 'productStore'])->whereNumber('product');
        Route::patch('products/{product}/media/{attachment}', [AdminMediaController::class, 'productUpdate'])
            ->whereNumber('product')->whereNumber('attachment');
        Route::post('products/{product}/media/{attachment}/primary', [AdminMediaController::class, 'productPrimary'])
            ->whereNumber('product')->whereNumber('attachment');
        Route::delete('products/{product}/media/{attachment}', [AdminMediaController::class, 'productDestroy'])
            ->whereNumber('product')->whereNumber('attachment');

        Route::get('sellable-items/{sellableItem}/media', [AdminMediaController::class, 'sellableItemIndex'])
            ->whereNumber('sellableItem');
        Route::post('sellable-items/{sellableItem}/media', [AdminMediaController::class, 'sellableItemStore'])
            ->whereNumber('sellableItem');
        Route::patch('sellable-items/{sellableItem}/media/{attachment}', [AdminMediaController::class, 'sellableItemUpdate'])
            ->whereNumber('sellableItem')->whereNumber('attachment');
        Route::post('sellable-items/{sellableItem}/media/{attachment}/primary', [AdminMediaController::class, 'sellableItemPrimary'])
            ->whereNumber('sellableItem')->whereNumber('attachment');
        Route::delete('sellable-items/{sellableItem}/media/{attachment}', [AdminMediaController::class, 'sellableItemDestroy'])
            ->whereNumber('sellableItem')->whereNumber('attachment');
    });
});
