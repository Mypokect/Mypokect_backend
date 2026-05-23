<?php

namespace App\Http\Requests\Tag;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CreateTagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza el nombre ANTES de la validación para que el unique check
     * trabaje con el mismo valor que usará el controlador.
     *
     * Bug original: el usuario enviaba "comida" (lowercase), el unique check
     * lo comparaba contra "Comida" en la DB y la rechazaba aunque el
     * controlador la hubiera normalizado igual. Ahora el name llega normalizado
     * tanto al validador como al controlador.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => ucfirst(mb_strtolower(trim($this->name))),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:50',
                // El unique check ya opera sobre el nombre normalizado (ucfirst+strtolower)
                // gracias a prepareForValidation(), evitando falsos positivos por diferencias de case.
                Rule::unique('tags', 'name')->where(function ($query) {
                    return $query->where('user_id', Auth::id());
                }),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la etiqueta es requerido',
            'name.string'   => 'El nombre debe ser texto',
            'name.max'      => 'El nombre no puede exceder 50 caracteres',
            'name.unique'   => 'Ya tienes una etiqueta con este nombre',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'status'  => 'error',
                'message' => 'Datos de etiqueta inválidos',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
