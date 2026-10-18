<?php

use App\Http\Controllers\Auth\AuthenticationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::controller(AuthenticationController::class)->group(function () {
    Route::post('/login', 'login');
    Route::post('/register', 'register');
});

Route::post('/logout', [AuthenticationController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::controller(PaymentController::class)->group(function () {
        Route::post('/payment/initiate/{id}', 'pay')->name('payments.pay');
    });

    Route::controller(PaymentGatewayController::class)->group(function () {
        Route::get('/payments/gateway', 'index')->name('getway.index');
        Route::post('/payments/gateway/store', 'store')->name('getway.store');
        Route::post('/payments/gateway/update/{id}', 'update')->name('getway.update');
        Route::post('/payments/gateway/status/{id}', 'status')->name('getway.status');
        Route::delete('/payments/gateway/delete/{id}', 'destroy')->name('getway.destroy');
    });
});

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);
