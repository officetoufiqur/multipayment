<?php

namespace App\Services\Payment;

use App\Concerns\PaymentGatewayInterface;
use App\Library\SslCommerz\SslCommerzNotification;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SslCommerzPaymentService implements PaymentGatewayInterface
{
    /**
     * Initiate SSLCommerz Payment
     */
    public function pay(array $data)
    {
        if (! SslCommerzConfig::load()) {
            return [
                'status' => false,
                'message' => 'SSLCommerz gateway is not active or configured.',
            ];
        }

        $product = $data['product'];
        $invoice = $data['invoice'];
        $payment = $data['payment'];
        $user = $data['user'];

        $postData = [
            'total_amount' => $payment->amount,
            'currency' => $payment->currency,
            'tran_id' => $payment->transaction_id,
            'cus_name' => $user->name,
            'cus_email' => $user->email,
            'cus_city' => 'Dhaka',
            'cus_country' => 'Bangladesh',
            'shipping_method' => 'NO',
            'product_name' => $product->name,

            'success_url' => route('payments.sslcommerz.success'),
            'fail_url' => route('payments.sslcommerz.fail'),
            'cancel_url' => route('payments.sslcommerz.cancel'),
            'ipn_url' => route('payments.sslcommerz.ipn'),

            'value_a' => (string) $product->id,
            'value_b' => (string) $invoice->id,
            'value_c' => (string) $payment->id,
        ];

        Log::info('Initiating SSLCommerz Payment with data:', $postData);
        try {
            $sslcommerz = new SslCommerzNotification;
            $response = $sslcommerz->makePayment($postData, 'checkout');
            $response = is_string($response) ? json_decode($response, true) : $response;
            Log::info('SSLCommerz Payment Response:', (array) $response);
            $redirectUrl = is_array($response)
                ? ($response['data'] ?? $response['GatewayPageURL'] ?? $response['redirectUrl'] ?? null)
                : null;

            if (! $redirectUrl) {
                Log::error('SSLCommerz Gateway Error:', ['response' => $response]);

                $payment->update(['status' => 'failed', 'payload' => (array) $response]);
                $invoice->update(['status' => 'failed']);

                return [
                    'status' => false,
                    'message' => is_array($response)
                        ? ($response['message'] ?? 'Failed to initiate gateway payment.')
                        : 'Failed to initiate gateway payment.',
                ];
            }

            $payment->update(['payload' => (array) $response]);

            Log::info('Redirecting to SSLCommerz Payment Gateway:', ['url' => $redirectUrl]);

            return [
                'status' => true,
                'redirect_url' => $redirectUrl,
            ];
        } catch (\Throwable $e) {
            report($e);
            $payment->update(['status' => 'failed', 'payload' => ['error' => $e->getMessage()]]);
            $invoice->update(['status' => 'failed']);

            return ['status' => false, 'message' => 'Payment gateway error: '.$e->getMessage()];
        }
    }

    /**
     * Verify SSLCommerz Payment
     */
    public function verify(Request $request): array
    {
        if (! SslCommerzConfig::load()) {
            return [
                'status' => false,
                'message' => 'SSLCommerz gateway is not active or configured.',
                'code' => 500,
            ];
        }
        Log::info('Verifying SSLCommerz Payment');
        $transactionId = $request->input('tran_id');

        if (! $transactionId) {
            return ['status' => false, 'message' => 'Transaction ID is missing.', 'code' => 400];
        }

        $paymentTemp = Payment::where('transaction_id', $transactionId)->first();

        if (! $paymentTemp) {
            return ['status' => false, 'message' => 'Payment not found.', 'code' => 404];
        }

        if ($paymentTemp->status === 'success') {
            return ['status' => true, 'message' => 'Payment already processed.', 'code' => 200];
        }

        try {
            $sslcommerz = new SslCommerzNotification;
            $validation = $sslcommerz->orderValidate(
                $request->all(),
                $transactionId,
                $paymentTemp->amount,
                $paymentTemp->currency
            );
        } catch (\Throwable $e) {
            report($e);

            return ['status' => false, 'message' => 'Unable to validate payment with gateway.', 'code' => 500];
        }

        if (! $validation) {
            $paymentTemp->update(['status' => 'failed', 'payload' => $request->all()]);
            $paymentTemp->invoice?->update(['status' => 'failed']);

            return ['status' => false, 'message' => 'Payment validation failed.', 'code' => 422];
        }

        // Transaction & Lock for Safe Concurrency
        try {
            return DB::transaction(function () use ($transactionId, $request) {
                $payment = Payment::with('invoice')
                    ->where('transaction_id', $transactionId)
                    ->lockForUpdate()
                    ->first();

                if ($payment->status === 'success') {
                    return ['status' => true, 'message' => 'Payment already processed.', 'code' => 200];
                }

                $invoice = $payment->invoice;

                $payment->update([
                    'status' => 'success',
                    'gateway_reference' => $request->input('val_id'),
                    'payment_method' => $request->input('card_type'),
                    'payload' => $request->all(),
                    'paid_at' => now(),
                ]);

                $newPaidAmount = $invoice->payments()->where('status', 'success')->sum('amount');
                $newDueAmount = max($invoice->total_amount - $newPaidAmount, 0);
                $invoiceStatus = $newDueAmount <= 0 ? 'paid' : ($newPaidAmount > 0 ? 'partially_paid' : 'unpaid');

                $invoice->update([
                    'paid_amount' => $newPaidAmount,
                    'due_amount' => $newDueAmount,
                    'status' => $invoiceStatus,
                ]);

                return ['status' => true, 'message' => 'Payment successfully verified.', 'code' => 200];
            });
        } catch (\Throwable $e) {
            report($e);

            return ['status' => false, 'message' => 'Payment processing failed during DB transaction.', 'code' => 500];
        }
    }
}
