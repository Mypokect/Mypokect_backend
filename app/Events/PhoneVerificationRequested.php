<?php

namespace App\Events;

use App\Models\PhoneVerification;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emitió un nuevo OTP para un teléfono (envío o reenvío).
 */
class PhoneVerificationRequested
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly PhoneVerification $verification) {}
}
