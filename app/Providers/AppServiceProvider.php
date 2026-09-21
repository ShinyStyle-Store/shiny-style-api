<?php

namespace App\Providers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellableItem;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'category' => Category::class,
            'product' => Product::class,
            'sellable_item' => SellableItem::class,
            'banner' => Banner::class,
        ]);

        RateLimiter::for('guest-orders', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        RateLimiter::for('admin-login', function (Request $request): Limit {
            $email = is_string($request->input('email'))
                ? strtolower(trim($request->input('email')))
                : '';
            $key = filter_var($email, FILTER_VALIDATE_EMAIL) === false ? 'ip' : $email;

            return Limit::perMinute(5)->by($key.'|'.$request->ip());
        });
    }
}
