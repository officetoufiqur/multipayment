<?php

namespace App\Services\Payment;

use App\Models\PaymentGetway;

class PaymentConfig
{
    public static function bkash(): array
    {
        $gateway = PaymentGetway::where('slug', 'bkash')
            ->where('is_active', true)
            ->firstOrFail();

        return [
            'base_url' => $gateway->base_url,
            'app_key' => $gateway->app_key,
            'app_secret' => $gateway->app_secret,
            'username' => $gateway->username,
            'password' => $gateway->password,
        ];
    }
}
