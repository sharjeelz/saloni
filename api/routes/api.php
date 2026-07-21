<?php

use App\Http\Controllers\Api\Admin\SalonAdminController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\PublicBookingController;
use App\Http\Controllers\Api\SalonSettingsController;
use App\Http\Controllers\Api\ServiceCategoryController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TimeOffController;
use App\Http\Controllers\Api\WorkingHourController;
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
| Public booking (hosted salon page — book.app/{slug})
|--------------------------------------------------------------------------
*/
// Manage an existing booking by opaque token (cancel / reschedule link).
Route::prefix('book/manage/{token}')->group(function () {
    Route::get('/', [PublicBookingController::class, 'manageShow']);
    Route::post('/cancel', [PublicBookingController::class, 'cancel']);
    Route::post('/reschedule', [PublicBookingController::class, 'reschedule']);
});

Route::prefix('book/{salon:slug}')->group(function () {
    Route::get('/', [PublicBookingController::class, 'salon']);
    Route::get('/widget', [PublicBookingController::class, 'widget']);
    Route::get('/branches', [PublicBookingController::class, 'branches']);
    Route::get('/services', [PublicBookingController::class, 'services']);
    Route::get('/availability', [PublicBookingController::class, 'availability']);
    Route::post('/otp', [PublicBookingController::class, 'requestOtp']);
    Route::post('/appointments', [PublicBookingController::class, 'confirm']);
});

/*
|--------------------------------------------------------------------------
| Authenticated + tenant-scoped routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Reads available to any authed member of the salon (owner + staff).
    Route::get('/branches', [BranchController::class, 'index']);
    Route::get('/branches/{branch}', [BranchController::class, 'show']);
    Route::get('/branches/{branch}/hours', [WorkingHourController::class, 'index']);
    Route::get('/service-categories', [ServiceCategoryController::class, 'index']);
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/services/{service}', [ServiceController::class, 'show']);
    Route::get('/salon', [SalonSettingsController::class, 'show']);

    // Time off — staff manage their own; owners manage anyone's + closures.
    Route::get('/time-off', [TimeOffController::class, 'index']);
    Route::post('/time-off', [TimeOffController::class, 'store']);
    Route::delete('/time-off/{timeOff}', [TimeOffController::class, 'destroy']);

    // Appointments — calendar, walk-ins, status (owner sees all; staff see own).
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::post('/appointments', [AppointmentController::class, 'store']);
    Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus']);

    // Catalog & configuration management — owner only.
    Route::middleware('role:owner')->group(function () {
        // Owner dashboard — bookings & revenue.
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Billing & subscriptions.
        Route::get('/billing', [BillingController::class, 'show']);
        Route::get('/billing/plans', [BillingController::class, 'plans']);
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);
        Route::post('/billing/cancel', [BillingController::class, 'cancel']);
        Route::get('/billing/invoices', [BillingController::class, 'invoices']);
        Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice']);

        // Staff
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff/invite', [StaffController::class, 'invite']);
        Route::patch('/staff/{staff}', [StaffController::class, 'update']);
        Route::patch('/staff/{staff}/deactivate', [StaffController::class, 'deactivate']);
        Route::patch('/staff/{staff}/activate', [StaffController::class, 'activate']);

        // Branches & hours
        Route::post('/branches', [BranchController::class, 'store']);
        Route::patch('/branches/{branch}', [BranchController::class, 'update']);
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy']);
        Route::put('/branches/{branch}/staff', [BranchController::class, 'syncStaff']);
        Route::put('/branches/{branch}/hours', [WorkingHourController::class, 'sync']);

        // Service categories
        Route::post('/service-categories', [ServiceCategoryController::class, 'store']);
        Route::patch('/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'update']);
        Route::delete('/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'destroy']);

        // Services + staff assignment
        Route::post('/services', [ServiceController::class, 'store']);
        Route::patch('/services/{service}', [ServiceController::class, 'update']);
        Route::delete('/services/{service}', [ServiceController::class, 'destroy']);
        Route::put('/services/{service}/staff', [ServiceController::class, 'syncStaff']);

        // Salon profile & branding
        Route::patch('/salon', [SalonSettingsController::class, 'update']);
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
