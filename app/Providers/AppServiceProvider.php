<?php

namespace App\Providers;

use App\Infrastructure\Cloudinary\CloudinaryFilesystemAdapter;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReviewImage;
use App\Models\SellableItem;
use Cloudinary\Cloudinary;
use GuzzleHttp\Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

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
        Storage::extend('cloudinary', function ($app, array $config) {
            $cloudName = (string) ($config['cloud_name'] ?? '');
            $apiKey = (string) ($config['api_key'] ?? '');
            $apiSecret = (string) ($config['api_secret'] ?? '');

            if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
                throw new \RuntimeException('Cloudinary configuration is incomplete.');
            }

            $cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => $cloudName,
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                ],
                'url' => ['secure' => (bool) ($config['secure'] ?? true)],
            ]);
            $adapter = new CloudinaryFilesystemAdapter(
                $cloudinary,
                new Client(['timeout' => (int) ($config['timeout'] ?? 30)]),
                $cloudName,
                (bool) ($config['secure'] ?? true),
                isset($config['folder']) ? (string) $config['folder'] : null,
                (int) ($config['timeout'] ?? 30),
                $app->storagePath('app/media-temp'),
            );

            return new FilesystemAdapter(
                new Filesystem($adapter),
                $adapter,
                $config,
            );
        });

        Relation::morphMap([
            'category' => Category::class,
            'product' => Product::class,
            'product_review_image' => ProductReviewImage::class,
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
