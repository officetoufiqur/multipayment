<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Payment\PaymentConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Throwable;

class StripeWebhookController extends Controller
{
    private string $secretKey;
    private string $webhookSecret;

    public function __construct()
    {
        $config = PaymentConfig::stripe();

        $this->secretKey = $config['secret_key'] ?? env('STRIPE_SECRET');
        $this->webhookSecret = $config['webhook_secret'] ?? env('STRIPE_WEBHOOK_SECRET');
    }

    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $this->webhookSecret
            );
        } catch (Throwable $e) {
            Log::error('Stripe Webhook Signature Failed: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $sessionId = $session->id;

            $transactionId = $session->metadata->transaction_id ?? null;

            try {
                return DB::transaction(function () use ($sessionId, $transactionId, $session, $event) {

                    $payment = Payment::with('invoice')
                        ->where(function ($query) use ($sessionId, $transactionId) {
                            $query->where('gateway_reference', $sessionId)
                                  ->orWhere('transaction_id', $transactionId);
                        })
                        ->lockForUpdate()
                        ->first();

                    if (! $payment || $payment->status === 'success') {
                        return response()->json(['received' => true]);
                    }

                    $invoice = $payment->invoice;

                    $payment->update([
                        'status' => 'success',
                        'gateway_reference' => $sessionId,
                        'payment_method' => 'stripe',
                        'payload' => json_decode(json_encode($event), true),
                        'paid_at' => now(),
                    ]);

                    if ($invoice) {
                        $newPaidAmount = $invoice->payments()->where('status', 'success')->sum('amount');
                        $newDueAmount = max($invoice->total_amount - $newPaidAmount, 0);
                        $invoiceStatus = $newDueAmount <= 0 ? 'paid' : ($newPaidAmount > 0 ? 'partially_paid' : 'unpaid');

                        $invoice->update([
                            'paid_amount' => $newPaidAmount,
                            'due_amount' => $newDueAmount,
                            'status' => $invoiceStatus,
                        ]);
                    }

                    return response()->json(['received' => true]);
                });
            } catch (Throwable $e) {
                Log::error('Stripe Webhook DB Transaction Error: ' . $e->getMessage());
                return response()->json(['error' => 'Database error'], 500);
            }
        }

        return response()->json(['received' => true]);
    }
}
