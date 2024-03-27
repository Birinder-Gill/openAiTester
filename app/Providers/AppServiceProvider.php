<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
    
        // Register OpenAiAnalysisService
        $this->app->singleton(\App\Services\OpenAiAnalysisService::class, function ($app) {
            return new \App\Services\OpenAiAnalysisService();
        });
}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
