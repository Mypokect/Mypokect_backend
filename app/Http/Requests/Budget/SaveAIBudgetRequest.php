<?php

namespace App\Http\Requests\Budget;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SaveAIBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'total_amount' => 'required|numeric|min:0.01',
            'categories' => 'required|array|min:1',
            'categories.*.name' => 'required|string|max:255',
            'categories.*.amount' => 'required|numeric|min:0.01',
            'categories.*.reason' => 'nullable|string|max:500',
            'language' => 'nullable|string|max:10|in:es,en',
            'plan_type' => 'nullable|string|in:travel,event,party,purchase,project,other',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors(),
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

        return abs($categoriesSum - $totalAmount) <= 0.01;
    }
}
