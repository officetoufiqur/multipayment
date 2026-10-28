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

    public static function stripe(): array
    {
        $gateway = PaymentGetway::where('slug', 'stripe')
            ->where('is_active', true)
            ->firstOrFail();

        return [
            'publishable_key' => $gateway->app_key,
            'secret_key' => $gateway->app_secret,
            'webhook' => $gateway->webhook,
        ];
    }

    public static function mpesa(): array
    {
        $gateway = PaymentGetway::where('slug', 'mpesa')
            ->where('is_active', true)
            ->firstOrFail();

        return [
            'base_url' => $gateway->base_url,
            'app_key' => $gateway->app_key,
            'app_secret' => $gateway->app_secret,
            'shortcode' => $gateway->shortcode,
            'password' => $gateway->password,
            'callback_url' => $gateway->callback_url,
        ];
    }
}
