<?php

namespace App\Services\Payment;

use App\Concerns\PaymentGatewayInterface;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MpesaPaymentService implements PaymentGatewayInterface
{
    private string $baseUrl;

    private string $consumerKey;

    private string $consumerSecret;

    private string $shortCode;

    private string $passKey;

    private string $callbackUrl;

    public function __construct()
    {
        $config = PaymentConfig::mpesa();

        $this->baseUrl = $config['base_url'] ?? '';
        $this->consumerKey = $config['app_key'] ?? '';
        $this->consumerSecret = $config['app_secret'] ?? '';
        $this->shortCode = $config['shortcode'] ?? '';
        $this->passKey = $config['password'] ?? '';
        $this->callbackUrl = $config['callback_url'] ?? '';
    }

    /**
     * Get OAuth access token from Safaricom Daraja.
     */
    private function getToken(): ?string
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(15)
                ->withBasicAuth(
                    $this->consumerKey,
                    $this->consumerSecret
                )
                ->acceptJson()
                ->get(
                    $this->baseUrl.'/oauth/v1/generate',
                    ['grant_type' => 'client_credentials']
                );

            Log::info('M-Pesa OAuth diagnostic', [
                'http_status' => $response->status(),
                'base_url' => $this->baseUrl,
                'consumer_key_present' => filled($this->consumerKey),
                'consumer_secret_present' => filled($this->consumerSecret),
                'response' => $response->json() ?? $response->body(),
            ]);

            if (! $response->successful()) {
                return null;
            }

            return $response->json('access_token');
        } catch (Throwable $e) {
            Log::error('M-Pesa OAuth error', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Normalize a Kenyan phone number.
     */
    private function normalizePhone(string $phone): ?string
    {
        $phone = preg_replace('/[\s\-]/', '', $phone);

        if (str_starts_with($phone, '+')) {
            $phone = substr($phone, 1);
        }

        if (preg_match('/^0[17]\d{8}$/', $phone)) {
            $phone = '254'.substr($phone, 1);
        } elseif (preg_match('/^[17]\d{8}$/', $phone)) {
            $phone = '254'.$phone;
        }

        return preg_match('/^254[17]\d{8}$/', $phone)
            ? $phone
            : null;
    }

    /**
     * Initiate M-Pesa STK Push.
     */
    public function pay(array $data): array
    {
        $invoice = $data['invoice'];
        $payment = $data['payment'];

        $phone = $this->normalizePhone(
            (string) ($data['phone'] ?? '')
        );

        if (! $phone) {
            $this->markFailed($payment, 'Invalid Kenyan M-Pesa phone number.');

            return [
                'status' => false,
                'message' => 'Invalid Kenyan M-Pesa phone number.',
            ];
        }

        if ((float) $payment->amount < 1) {
            $this->markFailed($payment, 'M-Pesa amount must be at least KES 1.');

            return [
                'status' => false,
                'message' => 'M-Pesa amount must be at least KES 1.',
            ];
        }

        $requiredConfiguration = [
            'base_url' => $this->baseUrl,
            'consumer_key' => $this->consumerKey,
            'consumer_secret' => $this->consumerSecret,
            'shortcode' => $this->shortCode,
            'passkey' => $this->passKey,
            'callback_url' => $this->callbackUrl,
        ];
        $missingConfiguration = array_keys(array_filter(
            $requiredConfiguration,
            fn (string $value): bool => blank($value)
        ));

        if ($missingConfiguration !== []) {
            Log::error('M-Pesa configuration is incomplete', [
                'missing' => $missingConfiguration,
            ]);
            $this->markFailed($payment, 'M-Pesa configuration is incomplete.');

            return [
                'status' => false,
                'message' => 'M-Pesa gateway configuration is incomplete. Configure its required fields in the payment gateway settings.',
            ];
        }

        $callbackUrl = $this->callbackUrl;

        if (
            filter_var($callbackUrl, FILTER_VALIDATE_URL) === false
            || parse_url($callbackUrl, PHP_URL_SCHEME) !== 'https'
        ) {
            $this->markFailed($payment, 'A public HTTPS M-Pesa callback URL is required.');

            return [
                'status' => false,
                'message' => 'A public HTTPS M-Pesa callback URL is required.',
            ];
        }

        $token = $this->getToken();

        if (! $token) {
            $this->markFailed($payment, 'M-Pesa authentication failed.');

            return [
                'status' => false,
                'message' => 'M-Pesa authentication failed.',
            ];
        }

        $shortcode = $this->shortCode;
        $passkey = $this->passKey;
        $timestamp = now()->format('YmdHis');

        $password = base64_encode(
            $shortcode.$passkey.$timestamp
        );

        // Daraja STK Push uses whole KES amounts.
        $amount = (int) round((float) $payment->amount);

        $accountReference = substr(
            (string) ($invoice->invoice_number ?? 'INV-'.$invoice->id),
            0,
            12
        );

        try {
            $response = Http::connectTimeout(5)
                ->timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->post(
                    $this->baseUrl.'/mpesa/stkpush/v1/processrequest',
                    [
                        'BusinessShortCode' => $shortcode,
                        'Password' => $password,
                        'Timestamp' => $timestamp,
                        'TransactionType' => 'CustomerPayBillOnline',
                        'Amount' => $amount,
                        'PartyA' => $phone,
                        'PartyB' => $shortcode,
                        'PhoneNumber' => $phone,
                        'CallBackURL' => $callbackUrl,
                        'AccountReference' => $accountReference,
                        'TransactionDesc' => 'Invoice payment',
                    ]
                );

            $result = $response->json() ?? [];

            if (
                ! $response->successful()
                || (string) ($result['ResponseCode'] ?? '') !== '0'
                || empty($result['CheckoutRequestID'])
            ) {
                Log::error('M-Pesa STK Push rejected', [
                    'payment_id' => $payment->id,
                    'response' => $result,
                ]);
                $this->markFailed($payment, 'M-Pesa STK Push was rejected.');

                return [
                    'status' => false,
                    'message' => $result['errorMessage']
                        ?? $result['ResponseDescription']
                        ?? 'M-Pesa STK Push failed.',
                ];
            }

            // Keep CheckoutRequestID as the gateway reference.
            // It is needed to match the asynchronous callback.
            $payment->update([
                'gateway_reference' => $result['CheckoutRequestID'],
                'payment_method' => 'mpesa',
                'status' => 'pending',
                'payload' => $result,
            ]);

            return [
                'status' => true,
                'message' => 'STK Push sent. Approve it on your phone.',
                'checkout_request_id' => $result['CheckoutRequestID'],
            ];
        } catch (Throwable $e) {
            Log::error('M-Pesa STK Push exception', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);

            // The request may have reached Safaricom even if a timeout
            // occurred. Reconcile before allowing a new payment attempt.
            return [
                'status' => false,
                'message' => 'Unable to confirm the STK Push request. Check payment status before retrying.',
            ];
        }
    }

    /**
     * Process the server-to-server Daraja callback.
     */
    public function verify(Request $request): array
    {
        $callback = $request->input('Body.stkCallback');

        if (! is_array($callback)) {
            Log::warning('Invalid M-Pesa callback payload');

            return [
                'status' => false,
                'message' => 'Invalid M-Pesa callback payload.',
                'code' => 400,
            ];
        }

        $checkoutId = $callback['CheckoutRequestID'] ?? null;
        $resultCode = $callback['ResultCode'] ?? null;

        if (! is_string($checkoutId) || ! is_numeric($resultCode)) {
            return [
                'status' => false,
                'message' => 'Checkout ID or result code missing.',
                'code' => 400,
            ];
        }

        $payment = Payment::where('gateway_name', 'mpesa')
            ->where('gateway_reference', $checkoutId)
            ->first();

        if (! $payment) {
            Log::warning('M-Pesa payment not found for callback', [
                'checkout_request_id' => $checkoutId,
            ]);

            return [
                'status' => false,
                'message' => 'Payment record not found.',
                'code' => 404,
            ];
        }

        if ($payment->status === 'success') {
            return [
                'status' => true,
                'message' => 'Payment already processed.',
                'code' => 200,
                'already_processed' => true,
            ];
        }

        $transactionStatus = $this->queryStkStatus($checkoutId);

        if (
            ! $transactionStatus
            || (string) ($transactionStatus['ResponseCode'] ?? '') !== '0'
            || ($transactionStatus['CheckoutRequestID'] ?? null) !== $checkoutId
            || ! is_numeric($transactionStatus['ResultCode'] ?? null)
        ) {
            Log::warning('M-Pesa callback could not be verified with Daraja', [
                'checkout_request_id' => $checkoutId,
            ]);

            return [
                'status' => false,
                'message' => 'Unable to verify the M-Pesa transaction with Safaricom.',
                'code' => 503,
            ];
        }

        if ((string) $transactionStatus['ResultCode'] !== (string) $resultCode) {
            Log::warning('M-Pesa callback result does not match Daraja status', [
                'checkout_request_id' => $checkoutId,
                'callback_result_code' => $resultCode,
                'daraja_result_code' => $transactionStatus['ResultCode'],
            ]);

            return [
                'status' => false,
                'message' => 'M-Pesa callback does not match Safaricom transaction status.',
                'code' => 409,
            ];
        }

        // Non-zero ResultCode means the STK transaction failed,
        // was cancelled, or timed out.
        if ((string) $resultCode !== '0') {
            $this->handleFailed($request);

            return [
                'status' => false,
                'message' => $callback['ResultDesc']
                    ?? 'M-Pesa payment failed.',
                'code' => 200,
            ];
        }

        $metadataItems = $callback['CallbackMetadata']['Item'] ?? [];

        if (! is_array($metadataItems)) {
            return [
                'status' => false,
                'message' => 'Required payment confirmation data missing.',
                'code' => 422,
            ];
        }

        $metadata = collect($metadataItems)->mapWithKeys(function ($item) {
            if (! is_array($item)) {
                return [];
            }

            return isset($item['Name'])
                ? [$item['Name'] => $item['Value'] ?? null]
                : [];
        });

        $receipt = $metadata->get('MpesaReceiptNumber');
        $paidAmount = $metadata->get('Amount');

        if (! $receipt || ! is_numeric($paidAmount)) {
            Log::error('M-Pesa success callback missing metadata', [
                'checkout_request_id' => $checkoutId,
                'callback' => $callback,
            ]);

            return [
                'status' => false,
                'message' => 'Required payment confirmation data missing.',
                'code' => 422,
            ];
        }

        try {
            return DB::transaction(function () use (
                $checkoutId,
                $callback,
                $receipt,
                $paidAmount
            ) {
                $payment = Payment::with('invoice')
                    ->where('gateway_name', 'mpesa')
                    ->where('gateway_reference', $checkoutId)
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    return [
                        'status' => false,
                        'message' => 'Payment record not found.',
                        'code' => 404,
                    ];
                }

                if ($payment->status === 'success') {
                    return [
                        'status' => true,
                        'message' => 'Payment already processed.',
                        'code' => 200,
                        'already_processed' => true,
                    ];
                }

                // Do not accept a callback for a different amount.
                if (
                    number_format((float) $paidAmount, 2, '.', '')
                    !== number_format((float) $payment->amount, 2, '.', '')
                ) {
                    Log::critical('M-Pesa amount mismatch', [
                        'payment_id' => $payment->id,
                        'expected' => $payment->amount,
                        'received' => $paidAmount,
                    ]);

                    return [
                        'status' => false,
                        'message' => 'Payment amount mismatch. Manual review required.',
                        'code' => 422,
                    ];
                }

                $invoice = $payment->invoice;

                if (! $invoice) {
                    return [
                        'status' => false,
                        'message' => 'Invoice not found.',
                        'code' => 404,
                    ];
                }

                // Keep gateway_reference unchanged for idempotency.
                // Store the M-Pesa receipt and callback payload separately.
                $payment->update([
                    'status' => 'success',
                    'payment_method' => 'mpesa',
                    'payload' => [
                        'checkout_request_id' => $checkoutId,
                        'mpesa_receipt_number' => $receipt,
                        'callback' => $callback,
                    ],
                    'paid_at' => now(),
                ]);

                $newPaidAmount = $invoice->payments()
                    ->where('status', 'success')
                    ->sum('amount');

                $newDueAmount = max(
                    (float) $invoice->total_amount - (float) $newPaidAmount,
                    0
                );

                $invoice->update([
                    'paid_amount' => $newPaidAmount,
                    'due_amount' => $newDueAmount,
                    'status' => $newDueAmount <= 0
                        ? 'paid'
                        : ($newPaidAmount > 0
                            ? 'partially_paid'
                            : 'unpaid'),
                ]);

                return [
                    'status' => true,
                    'message' => 'M-Pesa payment verified successfully.',
                    'code' => 200,
                    'receipt' => $receipt,
                ];
            });
        } catch (Throwable $e) {
            Log::error('M-Pesa callback processing error', [
                'checkout_request_id' => $checkoutId,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => false,
                'message' => 'Database error while processing M-Pesa callback.',
                'code' => 500,
            ];
        }
    }

    /**
     * Confirm callback transaction state against Daraja's status endpoint.
     *
     * @return array<string, mixed>|null
     */
    private function queryStkStatus(string $checkoutId): ?array
    {
        $token = $this->getToken();

        if (! $token) {
            return null;
        }

        $shortcode = $this->shortCode;
        $timestamp = now()->format('YmdHis');
        $password = base64_encode(
            $shortcode.$this->passKey.$timestamp
        );

        try {
            $response = Http::connectTimeout(5)
                ->timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->post(
                    $this->baseUrl.'/mpesa/stkpushquery/v1/query',
                    [
                        'BusinessShortCode' => $shortcode,
                        'Password' => $password,
                        'Timestamp' => $timestamp,
                        'CheckoutRequestID' => $checkoutId,
                    ]
                );

            $response->throw();
            $result = $response->json();

            return is_array($result) ? $result : null;
        } catch (Throwable $e) {
            Log::error('M-Pesa transaction status query failed', [
                'checkout_request_id' => $checkoutId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function markFailed(Payment $payment, string $reason): void
    {
        $payment->update([
            'status' => 'failed',
            'payload' => ['error' => $reason],
        ]);
    }

    /**
     * Mark a confirmed unsuccessful STK request as failed.
     */
    public function handleFailed(Request $request): void
    {
        $callback = $request->input('Body.stkCallback', []);
        $checkoutId = $callback['CheckoutRequestID'] ?? null;

        if (! is_string($checkoutId) || $checkoutId === '') {
            return;
        }

        DB::transaction(function () use ($callback, $checkoutId): void {
            $payment = Payment::where('gateway_name', 'mpesa')
                ->where('gateway_reference', $checkoutId)
                ->lockForUpdate()
                ->first();

            if ($payment && $payment->status !== 'success') {
                $payment->update([
                    'status' => 'failed',
                    'payload' => [
                        'checkout_request_id' => $checkoutId,
                        'result_code' => $callback['ResultCode'] ?? null,
                        'result_description' => $callback['ResultDesc'] ?? null,
                        'callback' => $callback,
                    ],
                ]);
            }
        });
    }

    /**
     * Daraja sends cancellations as a non-zero callback result.
     */
    public function handleCancel(Request $request): void
    {
        $this->handleFailed($request);
    }
}
