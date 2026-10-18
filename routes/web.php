<?php

use App\Http\Controllers\BkashController;
use App\Http\Controllers\SslCommerzeController;
use App\Http\Controllers\StripeController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::controller(SslCommerzeController::class)->group(function () {
    Route::post('/success', 'success')->name('payments.sslcommerz.success');
    Route::post('/fail', 'fail')->name('payments.sslcommerz.fail');
    Route::post('/cancel', 'cancel')->name('payments.sslcommerz.cancel');
    Route::post('/ipn', 'ipn')->name('payments.sslcommerz.ipn');
});

Route::controller(BkashController::class)->group(function () {
    Route::get('/payments/bkash/success', 'success')->name('payments.bkash.success');
});

Route::controller(StripeController::class)->group(function () {
    Route::get('/stripe/success', 'success')->name('stripe.success');
    Route::get('/stripe/cancel', 'cancel')->name('stripe.cancel');
});
