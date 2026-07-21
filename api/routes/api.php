<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OtpController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public auth routes
|--------------------------------------------------------------------------
*/
Route::post('/auth/signup', [AuthController::class, 'signup']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/otp/request', [OtpController::class, 'request']);
Route::post('/auth/otp/verify', [OtpController::class, 'verify']);

/*
|--------------------------------------------------------------------------
| Authenticated + tenant-scoped routes
|--------------------------------------------------------------------------
| `tenant` binds the current salon so every query is isolated automatically.
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});
