<?php

namespace App\Http\Controllers;

use App\Helpers\GenerateNumber;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Payment\PaymentFactory;
use App\Trait\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    use ApiResponse;

    public function pay(Request $request, $id)
    {
        $request->validate([
            'gateway' => ['required', 'string'],
        ]);

        $gatewayName = $request->input('gateway');
        $user = Auth::user();
        $product = Product::findOrFail($id);

        // Calculate Price
        $amount = $product->price;

        if ($amount <= 0) {
            return $this->errorResponse('Product price must be greater than 0', 400);
        }

        try {
            $data = DB::transaction(function () use ($user, $product, $amount, $gatewayName) {

                $invoiceNumber = GenerateNumber::generate(Invoice::class, 'invoice_number', 'INV');

                $invoice = Invoice::create([
                    'invoice_number' => $invoiceNumber,
                    'user_id' => $user->id,
                    'subtotal' => $amount,
                    'discount' => 0,
                    'tax' => 0,
                    'total_amount' => $amount,
                    'paid_amount' => 0,
                    'due_amount' => $amount,
                    'status' => 'pending',
                ]);

                $transactionId = GenerateNumber::generate(Payment::class, 'transaction_id', 'PAY');

                $payment = $invoice->payments()->create([
                    'transaction_id' => $transactionId,
                    'gateway_name' => $gatewayName,
                    'amount' => $amount,
                    'currency' => 'BDT',
                    'status' => 'pending',
                ]);

                return [
                    'product' => $product,
                    'invoice' => $invoice,
                    'payment' => $payment,
                ];
            });
        } catch (\Throwable $e) {
            report($e);

            return $this->errorResponse('Payment failed', 500);
        }

        // Execute Payment through Selected Gateway Service
        $gatewayService = PaymentFactory::make($gatewayName);
        $result = $gatewayService->pay([
            'product' => $data['product'],
            'invoice' => $data['invoice'],
            'payment' => $data['payment'],
            'user' => $user,
        ]);

        if (! $result['status']) {
            return $this->errorResponse($result['message'], 400);
        }

        return $this->successResponse(
            ['redirect_url' => $result['redirect_url']],
            'Payment initiated successfully.'
        );
    }
}
