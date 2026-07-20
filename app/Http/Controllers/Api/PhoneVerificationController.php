<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PhoneVerification\SendCodeRequest;
use App\Http\Requests\PhoneVerification\VerifyCodeRequest;
use App\Http\Resources\PhoneVerification\CodeSentResource;
use App\Http\Resources\PhoneVerification\VerifiedResource;
use App\Services\PhoneVerification\PhoneVerificationService;

/**
 * API de verificación de propiedad del teléfono vía OTP.
 *
 * Controlador deliberadamente delgado: la lógica vive en
 * PhoneVerificationService y los errores de dominio
 * (PhoneVerificationException) se renderizan solos con su status HTTP.
 */
class PhoneVerificationController extends Controller
{
    public function __construct(private readonly PhoneVerificationService $service) {}

    /**
     * POST /api/phone/send-code — genera y envía un OTP al teléfono.
     */
    public function sendCode(SendCodeRequest $request): CodeSentResource
    {
        $verification = $this->service->sendCode(
            $request->validated('telefono'),
            $request->user('sanctum')?->id,
        );

        return new CodeSentResource($verification);
    }

    /**
     * POST /api/phone/verify — valida el OTP y marca el teléfono verificado.
     */
    public function verify(VerifyCodeRequest $request): VerifiedResource
    {
        $verification = $this->service->verify(
            $request->validated('telefono'),
            $request->validated('codigo'),
        );

        return new VerifiedResource($verification);
    }

    /**
     * POST /api/phone/resend — reenvía un OTP nuevo (cooldown de 1 minuto).
     */
    public function resend(SendCodeRequest $request): CodeSentResource
    {
        $verification = $this->service->resendCode(
            $request->validated('telefono'),
            $request->user('sanctum')?->id,
        );

        return new CodeSentResource($verification);
    }
}
