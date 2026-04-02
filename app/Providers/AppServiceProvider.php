<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register policies
        Gate::policy(Reminder::class, ReminderPolicy::class);

        // OpenAPI documentation (Scramble)
        if (class_exists(Scramble::class)) {
            Scramble::afterOpenApiGenerated(function ($openApi) {
                $openApi->secure(
                    \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer', 'sanctum')
                );
            });
        }
    }
}
