<?php

namespace App\Http\Requests\Budget;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $budget = $this->route('budget');

        return $budget && $budget->user_id === auth()->id();
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'total_amount' => 'required|numeric|min:0.01',
            'status' => 'nullable|in:draft,active,archived',
            'categories' => 'required|array|min:1',
            'categories.*.id' => 'nullable|integer',
            'categories.*.name' => 'required|string|max:255',
            'categories.*.amount' => 'required|numeric|min:0.01',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'status'  => 'error',
                'message' => 'Datos de actualización inválidos',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }

    public function validatedCategoriesSum(): float
    {
        $categories = $this->input('categories', []);

        return array_sum(array_column($categories, 'amount'));
    }

    public function isCategoriesSumValid(): bool
    {
        $totalAmount = (float) $this->input('total_amount');
        $categoriesSum = $this->validatedCategoriesSum();

        return abs($categoriesSum - $totalAmount) <= 0.1;
    }
}
