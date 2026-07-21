<?php

namespace App\Providers;

use App\Services\Sms\ConfigSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, function ($app) {
            $config = $app['config']['sms'];
            $gateway = $config[$config['default']] ?? null;

            // Fall back to the log driver until the gateway is fully configured,
            // so local/dev works without live credentials.
            $others = $gateway['params']['others'] ?? [];
            $configured = $gateway
                && ! empty($others['apiKey'])
                && ! empty($others['userName']);

            if (! $configured) {
                return new LogSmsSender();
            }

            return new ConfigSmsSender($gateway, (string) ($config['country_code'] ?? '966'));
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
