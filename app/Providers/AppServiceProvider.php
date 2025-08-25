<?php

namespace App\Providers;

use App\Services\TusService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TusService::class, function ($app) {
            return new TusService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Create necessary directories for TUS
        $tusUploadDir = storage_path('app/tus-uploads');
        $tusCacheDir = storage_path('app/tus-cache');

        if (! file_exists($tusUploadDir)) {
            mkdir($tusUploadDir, 0755, true);
        }

        if (! file_exists($tusCacheDir)) {
            mkdir($tusCacheDir, 0755, true);
        }
    }
}
