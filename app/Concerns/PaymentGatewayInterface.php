<?php

namespace App\Concerns;

use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    public function pay(array $data);

    public function verify(Request $request);
}
