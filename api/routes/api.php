<?php

use App\Http\Controllers\Api\Admin\SalonAdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\StaffController;
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
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Staff management — owner only.
    Route::middleware('role:owner')->group(function () {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff/invite', [StaffController::class, 'invite']);
        Route::patch('/staff/{staff}/deactivate', [StaffController::class, 'deactivate']);
    });
});

/*
|--------------------------------------------------------------------------
| Super-admin — cross-tenant, no salon scope
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('admin')->group(function () {
    Route::get('/salons', [SalonAdminController::class, 'index']);
    Route::get('/salons/{salon}', [SalonAdminController::class, 'show']);
    Route::patch('/salons/{salon}/active', [SalonAdminController::class, 'setActive']);
});
