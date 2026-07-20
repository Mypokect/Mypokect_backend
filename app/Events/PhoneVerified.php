<?php

namespace App\Events;

use App\Models\PhoneVerification;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un teléfono fue verificado exitosamente con su OTP.
 */
class PhoneVerified
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly PhoneVerification $verification) {}
}
