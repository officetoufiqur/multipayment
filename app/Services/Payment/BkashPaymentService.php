<?php

namespace App\Services\Payment;

use App\Concerns\PaymentGatewayInterface;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class BkashPaymentService implements PaymentGatewayInterface
{
    private string $baseUrl;

    private string $appKey;

    private string $appSecret;

    private string $username;

    private string $password;

    public function __construct()
    {
        $config = PaymentConfig::bkash();

        $this->baseUrl = $config['base_url'];
        $this->appKey = $config['app_key'];
        $this->appSecret = $config['app_secret'];
        $this->username = $config['username'];
        $this->password = $config['password'];
    }

    /**
     * 1. Grant Token
     */
    private function getToken(): ?string
    {
        try {
            $endpoint = $this->baseUrl.'/tokenized/checkout/token/grant';

            $response = Http::withOptions([
                'verify' => false,
            ])->withHeaders([
                'username' => $this->username,
                'password' => $this->password,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($endpoint, [
                'app_key' => $this->appKey,
                'app_secret' => $this->appSecret,
            ]);

            $result = $response->json();

            Log::info('bKash Token Result:', [
                'status_code' => $response->status(),
                'api_status_code' => $result['statusCode'] ?? null,
                'api_status_message' => $result['statusMessage'] ?? null,
            ]);

            if ($response->successful() && ! empty($result['id_token'])) {
                return $result['id_token'];
            }

            return null;
        } catch (Throwable $e) {
            Log::error('bKash Token Exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * 2. Initiate Payment
     */
    public function pay(array $data)
    {
        $token = $this->getToken();

        if (! $token) {
            return ['status' => false, 'message' => 'bKash authentication failed (Token Error).'];
        }

        $product = $data['product'];
        $invoice = $data['invoice'];
        $payment = $data['payment'];
        $user = $data['user'];

        $callbackUrl = route('payments.bkash.success');
        Log::info('bKash Callback URL:', ['url' => $callbackUrl]);

        try {
            $endpoint = $this->baseUrl.'/tokenized/checkout/create';

            $response = Http::withOptions([
                'verify' => false,
            ])->withHeaders([
                'Authorization' => $token,
                'X-APP-Key' => $this->appKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($endpoint, [
                'mode' => '0011',
                'payerReference' => $user->phone ?? '01700000000', // Fix: User model property
                'callbackURL' => $callbackUrl,
                'amount' => (string) number_format((float) $payment->amount, 2, '.', ''),
                'currency' => 'BDT',
                'intent' => 'sale',
                'merchantInvoiceNumber' => $invoice->invoice_number,
                'productId' => (string) $product->id,
            ]);

            $result = $response->json();
            Log::info('bKash Create Payment Result:', $result);

            if (isset($result['bkashURL']) && isset($result['paymentID'])) {
                $payment->update([
                    'gateway_reference' => $result['paymentID'],
                    'payload' => $result,
                ]);

                return [
                    'status' => true,
                    'redirect_url' => $result['bkashURL'],
                ];
            }

            Log::error('bKash Create Payment Failed:', ['response' => $result]);
            $payment->update(['status' => 'failed', 'payload' => (array) $result]);
            $invoice->update(['status' => 'failed']);

            return ['status' => false, 'message' => $result['statusMessage'] ?? 'bKash Payment creation failed.'];
        } catch (Throwable $e) {
            Log::error('bKash Pay Exception: '.$e->getMessage());
            $payment->update(['status' => 'failed', 'payload' => ['error' => $e->getMessage()]]);
            $invoice->update(['status' => 'failed']);

            return ['status' => false, 'message' => 'bKash Gateway Error: '.$e->getMessage()];
        }
    }

    /**
     * 3. Verify / Execute Payment
     */
    public function verify(Request $request): array
    {
        $status = $request->input('status');
        $paymentID = $request->input('paymentID');

        Log::info('bKash Payment Status:', ['status' => $status, 'paymentID' => $paymentID]);

        if ($status === 'cancel') {
            $this->handleCancel($request);

            return ['status' => false, 'message' => 'bKash Payment was cancelled by user.', 'code' => 400];
        }

        if ($status === 'failure' || $status !== 'success') {
            $this->handleFailed($request);

            return ['status' => false, 'message' => 'bKash Payment failed.', 'code' => 400];
        }

        if (! $paymentID) {
            return ['status' => false, 'message' => 'Payment ID is missing in callback.', 'code' => 400];
        }

        $paymentTemp = Payment::where('gateway_reference', $paymentID)->first();

        if (! $paymentTemp) {
            return ['status' => false, 'message' => 'Associated Payment record not found.', 'code' => 404];
        }

        if ($paymentTemp->status === 'success') {
            return [
                'status' => true,
                'message' => 'Payment already processed.',
                'code' => 200,
                'already_processed' => true,
            ];
        }

        $token = session()->get('bkash_token') ?? $this->getToken();

        if (! $token) {
            return ['status' => false, 'message' => 'bKash Authentication Token missing.', 'code' => 401];
        }

        // Execute Payment Request to bKash API
        try {
            $response = Http::withOptions([
                'verify' => false,
            ])->withHeaders([
                'Authorization' => $token,
                'X-APP-Key' => $this->appKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl.'/tokenized/checkout/execute', [
                'paymentID' => $paymentID,
            ]);

            $result = $response->json();
        } catch (Throwable $e) {
            Log::error('bKash Execute Request Exception: '.$e->getMessage());

            return ['status' => false, 'message' => 'Failed to connect to bKash servers.', 'code' => 500];
        }

        if (
            ! isset($result['statusCode']) ||
            $result['statusCode'] !== '0000' ||
            ($result['transactionStatus'] ?? '') !== 'Completed'
        ) {
            Log::error('bKash Execution Validation Failed:', ['response' => $result]);

            $paymentTemp->update(['status' => 'failed', 'payload' => (array) $result]);
            $paymentTemp->invoice?->update(['status' => 'failed']);

            return [
                'status' => false,
                'message' => $result['statusMessage'] ?? 'bKash Payment verification failed.',
                'code' => 422,
            ];
        }

        // Database Transaction for Verification & Enrollment Activation
        try {
            return DB::transaction(function () use ($paymentID, $result) {
                $payment = Payment::with('invoice')
                    ->where('gateway_reference', $paymentID)
                    ->lockForUpdate()
                    ->first();

                if ($payment->status === 'success') {
                    return [
                        'status' => true,
                        'message' => 'Payment already processed.',
                        'code' => 200,
                        'already_processed' => true,
                    ];
                }

                $invoice = $payment->invoice;

                $payment->update([
                    'status' => 'success',
                    'gateway_reference' => $result['trxID'] ?? $paymentID,
                    'payment_method' => 'bkash',
                    'payload' => $result,
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

                return ['status' => true, 'message' => 'bKash Payment successfully verified!', 'code' => 200];
            });
        } catch (Throwable $e) {
            Log::error('bKash Processing Transaction Exception: '.$e->getMessage());

            return ['status' => false, 'message' => 'Database error during bKash verification.', 'code' => 500];
        }
    }

    public function handleFailed(Request $request)
    {
        $paymentID = $request->input('paymentID');
        Log::info('bKash Handle Failed Callback:', ['paymentID' => $paymentID]);

        if ($paymentID) {
            $payment = Payment::with('invoice')->where('gateway_reference', $paymentID)->first();

            if ($payment && $payment->status !== 'success') {
                $payment->update([
                    'status' => 'failed',
                    'payload' => $request->all(),
                ]);

                $payment->invoice?->update(['status' => 'failed']);
            }
        }
    }

    public function handleCancel(Request $request)
    {
        $paymentID = $request->input('paymentID');
        Log::info('bKash Handle Cancel Callback:', ['paymentID' => $paymentID]);

        if ($paymentID) {
            $payment = Payment::with('invoice')->where('gateway_reference', $paymentID)->first();

            if ($payment && $payment->status !== 'success') {
                $payment->update([
                    'status' => 'failed',
                    'payload' => $request->all(),
                ]);

                $payment->invoice?->update(['status' => 'cancelled']);
            }
        }
    }
}
