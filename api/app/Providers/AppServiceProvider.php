<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Billing\ManualPaymentGateway;
use App\Services\Billing\MoyasarPaymentGateway;
use App\Services\Billing\PaymentGateway;
use App\Services\Sms\ConfigSmsSender;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\WhatsApp\CloudApiWhatsAppSender;
use App\Services\WhatsApp\LogWhatsAppSender;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Gate;
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

        $this->app->singleton(WhatsAppSender::class, function ($app) {
            $config = $app['config']['whatsapp'];
            $cloud = $config['cloud'] ?? [];

            // Fall back to the log driver until Cloud API credentials are set.
            if (empty($cloud['token']) || empty($cloud['phone_number_id'])) {
                return new LogWhatsAppSender();
            }

            return new CloudApiWhatsAppSender(
                (string) $cloud['base_url'],
                (string) $cloud['phone_number_id'],
                (string) $cloud['token'],
                (string) ($config['country_code'] ?? '966'),
            );
        });

        $this->app->singleton(PaymentGateway::class, function ($app) {
            $config = $app['config']['payments'];

            return match ($config['gateway']) {
                'moyasar' => new MoyasarPaymentGateway(
                    $config['moyasar']['secret_key'],
                    $config['moyasar']['base_url'],
                ),
                default => new ManualPaymentGateway(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super-admin bypasses every gate.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // Owner-only abilities (managing the salon's setup & people).
        foreach (['manage-salon', 'manage-branches', 'manage-services', 'manage-staff', 'manage-billing'] as $ability) {
            Gate::define($ability, fn (User $user) => $user->isOwner());
        }

        // Both owners and staff can work with appointments/customers.
        Gate::define('manage-appointments', fn (User $user) => $user->isOwner() || $user->isStaff());
    }
}
