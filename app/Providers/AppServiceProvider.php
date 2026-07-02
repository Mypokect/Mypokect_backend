<?php

namespace App\Providers;

use App\Domains\Billing\Domain\Contracts\PaymentGateway;
use App\Domains\Billing\Infrastructure\Gateways\WompiGateway;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\Reminder;
use App\Policies\ReminderPolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        Reminder::class => ReminderPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pasarela de pago única: Wompi (puerto hexagonal -> adapter concreto).
        $this->app->bind(PaymentGateway::class, WompiGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register policies
        Gate::policy(Reminder::class, ReminderPolicy::class);

        // OpenAPI documentation (Scramble)
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer', 'sanctum')
            );
        });
    }
}
