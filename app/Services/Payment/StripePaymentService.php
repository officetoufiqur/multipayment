<?php

namespace App\Services\Payment;

use App\Concerns\PaymentGatewayInterface;
use App\Models\Payment;
use App\Services\Payment\PaymentConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Throwable;

class StripePaymentService implements PaymentGatewayInterface
{
    private string $appKey;
    private string $appSecret;
    private string $webhook;

    public function __construct()
    {
        $config = PaymentConfig::stripe();

        $this->appKey = $config['publishable_key'];
        $this->appSecret = $config['secret_key'];
        $this->webhook = $config['webhook'];
    }

    /**
     * Initiate Stripe Checkout Payment
     */
    public function pay(array $data)
    {
        $product = $data['product'];
        $invoice = $data['invoice'];
        $payment = $data['payment'];
        $user = $data['user'];

        try {
            Stripe::setApiKey($this->appSecret ?? env('STRIPE_SECRET'));

            $successUrl = route('stripe.success') . '?session_id={CHECKOUT_SESSION_ID}&tran_id=' . $payment->transaction_id;
            $cancelUrl = route('stripe.cancel') . '?tran_id=' . $payment->transaction_id;

            $session = Session::create([
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($payment->currency),
                        'product_data' => [
                            'name' => $product->name,
                        ],
                        'unit_amount' => (int) ($payment->amount * 100),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $user->email,
                'metadata' => [
                    'transaction_id' => $payment->transaction_id,
                    'invoice_id' => $invoice->id,
                ],
            ]);

            $payment->update([
                'gateway_reference' => $session->id,
                'payload' => $session->toArray(),
            ]);

            return [
                'status' => true,
                'redirect_url' => $session->url,
            ];
        } catch (Throwable $e) {
            Log::error('Stripe Pay Exception: ' . $e->getMessage());
            $payment->update(['status' => 'failed', 'payload' => ['error' => $e->getMessage()]]);
            $invoice->update(['status' => 'failed']);

            return ['status' => false, 'message' => 'Stripe Gateway Error: ' . $e->getMessage()];
        }
    }

    /**
     * Verify / Execute Stripe Payment
     */
    public function verify(Request $request): array
    {
        $sessionId = $request->input('session_id');
        $transactionId = $request->input('tran_id');

        Log::info('Stripe Verification Request:', ['session_id' => $sessionId, 'tran_id' => $transactionId]);

        if (! $transactionId) {
            return ['status' => false, 'message' => 'Transaction ID is missing.', 'code' => 400];
        }

        $paymentTemp = Payment::where('transaction_id', $transactionId)->first();

        if (! $paymentTemp) {
            return ['status' => false, 'message' => 'Payment record not found.', 'code' => 404];
        }

        if ($paymentTemp->status === 'success') {
            return ['status' => true, 'message' => 'Payment already processed.', 'code' => 200];
        }

        try {
            Stripe::setApiKey($this->appSecret ?? env('STRIPE_SECRET'));

            if ($sessionId) {
                $session = Session::retrieve($sessionId);
                if ($session->payment_status !== 'paid') {
                    return ['status' => false, 'message' => 'Stripe payment not completed.', 'code' => 422];
                }
            }
        } catch (Throwable $e) {
            Log::error('Stripe Retrieve Exception: ' . $e->getMessage());
            return ['status' => false, 'message' => 'Unable to validate payment with Stripe.', 'code' => 500];
        }

        // Database Transaction & Concurrency Lock
        try {
            return DB::transaction(function () use ($transactionId, $sessionId, $request) {
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
                    'gateway_reference' => $sessionId ?? $payment->gateway_reference,
                    'payment_method' => 'stripe',
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

                return ['status' => true, 'message' => 'Stripe Payment successfully verified!', 'code' => 200];
            });
        } catch (Throwable $e) {
            Log::error('Stripe Database Transaction Exception: ' . $e->getMessage());
            return ['status' => false, 'message' => 'Database error during Stripe verification.', 'code' => 500];
        }
    }
}
