<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;

class TransactionController extends Controller
{
    public function confirmPayment($id)
    {
        return response()->json(['message' => 'Método pendiente de implementar'], 200);
    }
}
