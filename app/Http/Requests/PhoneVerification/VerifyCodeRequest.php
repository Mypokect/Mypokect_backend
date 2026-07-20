<?php

namespace App\Http\Requests\PhoneVerification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del código OTP recibido por el usuario.
 */
class VerifyCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'telefono' => ['required', 'string', 'regex:'.SendCodeRequest::E164_REGEX],
            'codigo' => ['required', 'digits:6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'telefono.required' => 'El teléfono es obligatorio.',
            'telefono.regex' => 'El teléfono debe estar en formato E.164, por ejemplo +573001234567.',
            'codigo.required' => 'El código es obligatorio.',
            'codigo.digits' => 'El código debe tener exactamente 6 dígitos.',
        ];
    }
}
