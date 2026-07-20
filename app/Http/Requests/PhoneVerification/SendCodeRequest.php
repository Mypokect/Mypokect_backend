<?php

namespace App\Http\Requests\PhoneVerification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solicitud de envío (o reenvío) del código de verificación.
 * Exige el teléfono en formato E.164 estricto: +[código país][número].
 */
class SendCodeRequest extends FormRequest
{
    /** Regex E.164: '+' seguido de 8 a 15 dígitos sin cero inicial. */
    public const E164_REGEX = '/^\+[1-9]\d{7,14}$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'telefono' => ['required', 'string', 'regex:'.self::E164_REGEX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'telefono.required' => 'El teléfono es obligatorio.',
            'telefono.regex' => 'El teléfono debe estar en formato E.164, por ejemplo +573001234567.',
        ];
    }
}
