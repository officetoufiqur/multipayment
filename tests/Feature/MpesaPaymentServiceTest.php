<?php

use App\Models\Invoice;
use App\Models\PaymentGetway;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function createMpesaGateway(): void
{
    PaymentGetway::create([
        'name' => 'M-Pesa',
        'slug' => 'mpesa',
        'base_url' => 'https://sandbox.safaricom.co.ke',
        'callback_url' => 'https://example.test/api/mpesa/callback',
        'app_key' => 'consumer-key',
        'app_secret' => 'consumer-secret',
        'shortcode' => '174379',
        'password' => 'passkey',
        'is_active' => true,
    ]);
}

it('starts an M-Pesa STK push for a whole KES product price', function () {
    createMpesaGateway();

    Http::preventStrayRequests();
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
            'access_token' => 'test-access-token',
        ]),
        'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
            'ResponseCode' => '0',
            'CheckoutRequestID' => 'ws_CO_123456789',
        ]),
    ]);

    $user = User::factory()->create();
    $product = Product::create([
        'name' => 'Course',
        'description' => 'Course access',
        'price' => 999.6,
    ]);

    $response = $this->actingAs($user)->postJson(route('payments.pay', $product), [
        'gateway' => 'mpesa',
        'phone' => '0712345678',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.checkout_request_id', 'ws_CO_123456789')
        ->assertJsonPath('message', 'STK Push sent. Approve it on your phone.');

    $this->assertDatabaseHas('payments', [
        'gateway_name' => 'mpesa',
        'amount' => 1000,
        'currency' => 'KES',
        'status' => 'pending',
        'gateway_reference' => 'ws_CO_123456789',
    ]);

    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/mpesa/stkpush/v1/processrequest')
            && $request['Amount'] === 1000
            && $request['PartyA'] === '254712345678'
            && $request['PhoneNumber'] === '254712345678'
    );
});

it('records a successful M-Pesa callback and settles its invoice', function () {
    createMpesaGateway();

    Http::preventStrayRequests();
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
            'access_token' => 'test-access-token',
        ]),
        'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
            'ResponseCode' => '0',
            'CheckoutRequestID' => 'ws_CO_123456789',
            'ResultCode' => '0',
            'ResultDesc' => 'The service request is processed successfully.',
        ]),
    ]);
    $user = User::factory()->create();
    $invoice = Invoice::create([
        'invoice_number' => 'INV-MPESA-1',
        'user_id' => $user->id,
        'subtotal' => 1000,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'due_amount' => 1000,
        'status' => 'pending',
    ]);
    $payment = $invoice->payments()->create([
        'transaction_id' => 'PAY-MPESA-1',
        'gateway_name' => 'mpesa',
        'amount' => 1000,
        'currency' => 'KES',
        'status' => 'pending',
        'gateway_reference' => 'ws_CO_123456789',
    ]);

    $response = $this->postJson(route('payments.mpesa.callback'), [
        'Body' => [
            'stkCallback' => [
                'CheckoutRequestID' => 'ws_CO_123456789',
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
                'CallbackMetadata' => [
                    'Item' => [
                        ['Name' => 'Amount', 'Value' => 1000],
                        ['Name' => 'MpesaReceiptNumber', 'Value' => 'QGH123ABC'],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('ResultCode', 0);

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'success',
        'payment_method' => 'mpesa',
    ]);
    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'paid_amount' => 1000,
        'due_amount' => 0,
        'status' => 'paid',
    ]);
});

it('acknowledges unsuccessful M-Pesa callbacks and marks their payment failed', function () {
    createMpesaGateway();

    Http::preventStrayRequests();
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
            'access_token' => 'test-access-token',
        ]),
        'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
            'ResponseCode' => '0',
            'CheckoutRequestID' => 'ws_CO_987654321',
            'ResultCode' => '1032',
            'ResultDesc' => 'Request cancelled by user.',
        ]),
    ]);
    $user = User::factory()->create();
    $invoice = Invoice::create([
        'invoice_number' => 'INV-MPESA-2',
        'user_id' => $user->id,
        'subtotal' => 1000,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'due_amount' => 1000,
        'status' => 'pending',
    ]);
    $payment = $invoice->payments()->create([
        'transaction_id' => 'PAY-MPESA-2',
        'gateway_name' => 'mpesa',
        'amount' => 1000,
        'currency' => 'KES',
        'status' => 'pending',
        'gateway_reference' => 'ws_CO_987654321',
    ]);

    $response = $this->postJson(route('payments.mpesa.callback'), [
        'Body' => [
            'stkCallback' => [
                'CheckoutRequestID' => 'ws_CO_987654321',
                'ResultCode' => 1032,
                'ResultDesc' => 'Request cancelled by user.',
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('ResultCode', 0);

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'failed',
    ]);
    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'due_amount' => 1000,
        'status' => 'pending',
    ]);
});

it('rejects a callback whose result does not match Daraja transaction status', function () {
    createMpesaGateway();

    Http::preventStrayRequests();
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
            'access_token' => 'test-access-token',
        ]),
        'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => Http::response([
            'ResponseCode' => '0',
            'CheckoutRequestID' => 'ws_CO_246801357',
            'ResultCode' => '1032',
            'ResultDesc' => 'Request cancelled by user.',
        ]),
    ]);
    $user = User::factory()->create();
    $invoice = Invoice::create([
        'invoice_number' => 'INV-MPESA-3',
        'user_id' => $user->id,
        'subtotal' => 1000,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'due_amount' => 1000,
        'status' => 'pending',
    ]);
    $payment = $invoice->payments()->create([
        'transaction_id' => 'PAY-MPESA-3',
        'gateway_name' => 'mpesa',
        'amount' => 1000,
        'currency' => 'KES',
        'status' => 'pending',
        'gateway_reference' => 'ws_CO_246801357',
    ]);

    $response = $this->postJson(route('payments.mpesa.callback'), [
        'Body' => [
            'stkCallback' => [
                'CheckoutRequestID' => 'ws_CO_246801357',
                'ResultCode' => 0,
                'CallbackMetadata' => [
                    'Item' => [
                        ['Name' => 'Amount', 'Value' => 1000],
                        ['Name' => 'MpesaReceiptNumber', 'Value' => 'FAKE123ABC'],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('ResultDesc', 'M-Pesa callback does not match Safaricom transaction status.');

    $this->assertDatabaseHas('payments', [
        'id' => $payment->id,
        'status' => 'pending',
    ]);
    $this->assertDatabaseHas('invoices', [
        'id' => $invoice->id,
        'due_amount' => 1000,
        'status' => 'pending',
    ]);
});

it('returns a configuration error instead of calling Safaricom when gateway credentials are missing', function () {
    createMpesaGateway();
    PaymentGetway::where('slug', 'mpesa')->firstOrFail()->update([
        'base_url' => null,
        'callback_url' => null,
        'app_key' => null,
        'app_secret' => null,
        'shortcode' => null,
        'password' => null,
    ]);

    Http::preventStrayRequests();

    $user = User::factory()->create();
    $product = Product::create([
        'name' => 'Course',
        'description' => 'Course access',
        'price' => 1000,
    ]);

    $response = $this->actingAs($user)->postJson(route('payments.pay', $product), [
        'gateway' => 'mpesa',
        'phone' => '0712345678',
    ]);

    $response->assertBadRequest()
        ->assertJsonPath('message', 'M-Pesa gateway configuration is incomplete. Configure its required fields in the payment gateway settings.');
    Http::assertNothingSent();
    $this->assertDatabaseHas('payments', [
        'gateway_name' => 'mpesa',
        'status' => 'failed',
    ]);
});

it('requires a phone number before initiating M-Pesa payment', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $product = Product::create([
        'name' => 'Course',
        'description' => 'Course access',
        'price' => 1000,
    ]);

    $response = $this->actingAs($user)->postJson(route('payments.pay', $product), [
        'gateway' => 'mpesa',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('payments', 0);
    Http::assertNothingSent();
});
