<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReminderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by controller/policy
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:120'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'due_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'recurrence' => ['sometimes', 'in:none,monthly'],
            'recurrence_params' => ['sometimes', 'nullable', 'array'],
            'recurrence_params.dayOfMonth' => ['required_if:recurrence,monthly', 'integer', 'between:1,31'],
            'notify_offset_minutes' => ['sometimes', 'integer', 'in:0,60,120,360,720,1440,2880,4320'],
            'status' => ['sometimes', 'in:pending,paid'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.max' => 'El título no puede exceder 120 caracteres.',
            'amount.numeric' => 'El monto debe ser un número válido.',
            'amount.min' => 'El monto debe ser mayor o igual a 0.',
            'due_date.after_or_equal' => 'La fecha de vencimiento no puede ser en el pasado.',
            'timezone.timezone' => 'La zona horaria no es válida.',
            'recurrence.in' => 'La recurrencia debe ser "none" o "monthly".',
            'recurrence_params.dayOfMonth.required_if' => 'Debe especificar el día del mes para recurrencia mensual.',
            'recurrence_params.dayOfMonth.between' => 'El día del mes debe estar entre 1 y 31.',
            'notify_offset_minutes.in' => 'La anticipación debe ser uno de los valores permitidos.',
        ];
    }
}
