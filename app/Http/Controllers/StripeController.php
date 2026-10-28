<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payment\PaymentFactory;
use App\Trait\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StripeController extends Controller
{
    use ApiResponse;

    /**
     * Stripe Success Callback.
     */
    public function success(Request $request)
    {
        $transactionId = $request->input('tran_id');
        $payment = null;

        if ($transactionId) {
            $payment = Payment::with('invoice.user')->where('transaction_id', $transactionId)->first();

            if (! Auth::check() && $payment && $payment->invoice && $payment->invoice->user) {
                Auth::login($payment->invoice->user);
                $request->session()->regenerate();
            }
        }

        // Stripe Payment Verification Process
        $service = PaymentFactory::make('stripe');
        $result = $service->verify($request);

        $isSuccess = is_array($result) ? ($result['status'] ?? false) : (bool) $result;

        if ($isSuccess) {
            return $this->successResponse(
                $payment,
                'Stripe payment successfully verified and enrollment activated!',
            );
        }

        return $this->errorResponse(
            $result['message'] ?? 'Stripe payment verification failed.',
            $result['code'] ?? 400
        );
    }

    /**
     * Stripe Cancel Callback.
     */
    public function cancel(Request $request)
    {
        $transactionId = $request->input('tran_id');

        if ($transactionId) {
            $payment = Payment::with('invoice')->where('transaction_id', $transactionId)->first();

            if ($payment && $payment->status !== 'success') {
                $payment->update([
                    'status' => 'failed',
                    'payload' => $request->all(),
                ]);

                $payment->invoice?->update(['status' => 'cancelled']);
            }
        }

        return $this->errorResponse('Stripe payment was cancelled.');
    }
}
