<?php

use App\Http\Controllers\Auth\AuthenticationController;
use Illuminate\Support\Facades\Route;

Route::controller(AuthenticationController::class)->group(function () {
    Route::post('/login', 'login');
    Route::post('/register', 'register');
});

Route::post('/logout', [AuthenticationController::class, 'logout'])->middleware('auth:sanctum');
