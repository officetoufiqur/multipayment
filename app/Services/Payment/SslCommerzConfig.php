<?php

namespace App\Services\Payment;

use App\Models\PaymentGetway;
use Illuminate\Support\Facades\Config;

class SslCommerzConfig
{
    public static function load(): bool
    {
        $gateway = PaymentGetway::where('slug', 'sslcommerz')
            ->where('is_active', true)
            ->first();

        if (! $gateway) {
            return false;
        }

        $apiDomain = $gateway->is_testmode
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';

        Config::set('sslcommerz.apiCredentials', [
            'store_id' => $gateway->store_id,
            'store_password' => $gateway->password,
        ]);

        Config::set('sslcommerz.apiDomain', $apiDomain);

        Config::set(
            'sslcommerz.connect_from_localhost',
            (bool) $gateway->is_localhost
        );

        return true;
    }
}
