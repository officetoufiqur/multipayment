<?php

namespace Database\Seeders;

use App\Models\PaymentGetway;
use Illuminate\Database\Seeder;

class PaymentGatewaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PaymentGetway::updateOrCreate(
            ['slug' => 'sslcommerz'],
            [
                'name' => 'SSLCommerz',
                'slug' => 'sslcommerz',
                'logo' => null,
                'base_url' => 'https://sandbox.sslcommerz.com',
                'username' => null,
                'password' => 'XwVlud71yHdP',
                'app_key' => null,
                'app_secret' => null,
                'store_id' => 'acade6aafa0aa530f3',
                'is_active' => true,
                'is_testmode' => true,
                'is_localhost' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        PaymentGetway::updateOrCreate(
            ['slug' => 'bkash'],
            [
                'name' => 'bKash',
                'slug' => 'bkash',
                'logo' => null,
                'base_url' => 'https://tokenized.sandbox.bka.sh/v1.2.0-beta',
                'username' => 'sandboxTokenizedUser02',
                'password' => 'sandboxTokenizedUser02@12345',
                'app_key' => '4f6o0cjiki2rfm34kfdadl1eqq',
                'app_secret' => '2is7hdktrekvrbljjh44ll3d9l1dtjo4pasmjvs5vl5qr3fug4b',
                'store_id' => null,
                'is_active' => true,
                'is_testmode' => true,
                'is_localhost' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
