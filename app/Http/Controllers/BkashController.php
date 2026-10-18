<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payment\PaymentFactory;
use App\Trait\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class BkashController extends Controller
{
    use ApiResponse;

    public function success(Request $request)
    {
        try {
            $service = PaymentFactory::make('bkash');

            $result = $service->verify($request);

            $paymentId = (string) $request->input('paymentID');

            $payment = Payment::where('gateway_reference', $paymentId)
                ->with(['invoice'])
                ->first();

            if (! ($result['status'] ?? false)) {
                return $this->errorResponse(
                    $result['message'] ?? 'Payment verification failed.',
                    $result['code'] ?? 400
                );
            }

            return $this->successResponse(
                'Payment successfully verified and enrollment activated!',
                $payment
            );

        } catch (Throwable $e) {
            Log::error('Bkash Success Controller Exception: '.$e->getMessage());

            return $this->errorResponse('Something went wrong', 500);
        }
    }
}
