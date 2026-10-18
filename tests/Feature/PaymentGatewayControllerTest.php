<?php

use App\Models\PaymentGetway;
use App\Models\User;

it('keeps the existing slug when updating a gateway without changing its name', function () {
    $user = User::factory()->create();
    $gateway = PaymentGetway::create([
        'name' => 'Stripe',
        'slug' => 'stripe-custom',
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('getway.update', $gateway), [
            'name' => 'Stripe',
        ]);

    $response->assertSuccessful();

    $this->assertDatabaseHas('payment_getways', [
        'id' => $gateway->id,
        'name' => 'Stripe',
        'slug' => 'stripe-custom',
    ]);
});
