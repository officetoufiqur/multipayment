<?php

use App\Http\Controllers\Auth\AuthenticationController;
use App\Http\Controllers\PaymentController;
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
});
