<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TransactionController extends Controller
{
     public function confirmPayment($id)
    {
        return response()->json(['message' => 'Método pendiente de implementar'], 200);
    }
}
