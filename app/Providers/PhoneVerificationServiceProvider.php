<?php

namespace App\Providers;

use App\Services\PhoneVerification\Contracts\NotificationProviderInterface;
use App\Services\PhoneVerification\Providers\SmsNotificationProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Bindea el canal de entrega de OTPs.
 *
 * Para cambiar de proveedor (WhatsApp, otro agregador SMS) basta con crear
 * otra implementación de NotificationProviderInterface y bindearla aquí.
 * Los tests re-bindean FakeNotificationProvider en su setUp.
 */
class PhoneVerificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationProviderInterface::class, SmsNotificationProvider::class);
    }
}
