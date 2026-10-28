<?php

namespace App\Http\Controllers;

use App\Services\Payment\PaymentFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MpesaController extends Controller
{
    public function callback(Request $request): JsonResponse
    {
        $result = PaymentFactory::make('mpesa')->verify($request);
        $httpStatus = $result['code'] ?? 500;
        $accepted = $httpStatus >= 200 && $httpStatus < 300;

        return response()->json([
            'ResultCode' => $accepted ? 0 : $httpStatus,
            'ResultDesc' => $result['message'] ?? 'M-Pesa callback processing failed.',
        ], $httpStatus);
    }
}
