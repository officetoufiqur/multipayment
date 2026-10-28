<?php

namespace App\Services\Payment;

use App\Concerns\PaymentGatewayInterface;

class PaymentFactory
{
    public static function make(string $gateway): PaymentGatewayInterface
    {
        return match ($gateway) {
            'sslcommerz' => new SslCommerzPaymentService,
            'bkash' => new BkashPaymentService,
            'stripe' => new StripePaymentService,
            'mpesa' => new MpesaPaymentService,
            default => throw new \Exception("Unsupported payment gateway: {$gateway}"),
        };
    }
}
