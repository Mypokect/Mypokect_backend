<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class TransactionController extends Controller
{
    use ApiResponse;

    public function confirmPayment(int $id): JsonResponse
    {
        return $this->errorResponse('Método pendiente de implementar.', 501);
    }
}
