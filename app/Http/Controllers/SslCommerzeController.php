<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payment\PaymentFactory;
use App\Trait\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SslCommerzeController extends Controller
{
    use ApiResponse;

    /**
     * SSLCommerz Success Callback.
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

        // Payment verification process
        $service = PaymentFactory::make('sslcommerz');
        $result = $service->verify($request);

        // Verification Check
        $isSuccess = is_array($result) ? ($result['status'] ?? false) : (bool) $result;

        if ($isSuccess) {
            return $this->successResponse(
                'Payment successfully verified and enrollment activated!',
                $payment
            );
        }

        return $this->errorResponse(
            $result['message'] ?? 'Payment verification failed.',
            $result['code'] ?? 400
        );
    }

    /**
     * SSLCommerz IPN Callback.
     */
    public function ipn(Request $request)
    {
        $service = PaymentFactory::make('sslcommerz');
        $result = $service->verify($request);

        return response()->json([
            'success' => $result['status'] ?? false,
            'message' => $result['message'] ?? 'IPN processed.',
        ], $result['code'] ?? 200);
    }

    /**
     * SSLCommerz Failed Callback.
     */
    public function fail(Request $request)
    {
        $transactionId = $request->input('tran_id');

        if ($transactionId) {
            $payment = Payment::with('invoice')->where('transaction_id', $transactionId)->first();

            if ($payment && $payment->status !== 'success') {
                $payment->update([
                    'status' => 'failed',
                    'payload' => $request->all(),
                ]);

                $payment->invoice?->update(['status' => 'failed']);
            }
        }

        if ($request->wantsJson() || $request->isJson()) {
            return response()->json(['success' => false, 'message' => 'Payment failed.'], 400);
        }

        return $this->errorResponse('Payment failed.');
    }

    /**
     * SSLCommerz Cancel Callback.
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

        if ($request->wantsJson() || $request->isJson()) {
            return response()->json(['success' => false, 'message' => 'Payment cancelled.'], 400);
        }

        return $this->errorResponse('Payment cancelled.');
    }
}
