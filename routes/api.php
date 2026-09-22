<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\BookingApprovalController;
use App\Http\Controllers\Api\Admin\ComplaintController as AdminComplaintController;
use App\Http\Controllers\Api\Admin\ContractController;
use App\Http\Controllers\Api\Admin\FacilityController;
use App\Http\Controllers\Api\Admin\InvoiceController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\RoomController;
use App\Http\Controllers\Api\Admin\TenancyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\Public\PublicBookingController;
use App\Http\Controllers\Api\Public\PublicRoomController;
use App\Http\Controllers\Api\Tenant\TenantAuthController;
use App\Http\Controllers\Api\Tenant\TenantComplaintController;
use App\Http\Controllers\Api\Tenant\TenantContractController;
use App\Http\Controllers\Api\Tenant\TenantInvoiceController;
use App\Http\Controllers\Api\Tenant\TenantPaymentController;
use App\Http\Controllers\Api\Tenant\TenantProfileController;
use App\Http\Middleware\EnsurePasswordHasBeenChanged;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public Authentication
Route::post('/login', [AuthController::class, 'login']);
Route::post('/tenant/login', [TenantAuthController::class, 'login']);

// Public Catalog & Bookings
Route::prefix('public')->group(function () {
    Route::get('/rooms', [PublicRoomController::class, 'index']);
    Route::get('/rooms/{id}', [PublicRoomController::class, 'show']);
    Route::post('/bookings', [PublicBookingController::class, 'store'])->middleware('throttle:5,1');
});

// Payment Gateway Webhook (Signature verified & Idempotent)
Route::post('/webhook/payment/{provider}', [PaymentWebhookController::class, 'handle']);

// Temporary Signed URL for Protected KTP Access (5 min expiry)
Route::get('/admin/bookings/{id}/ktp', [BookingApprovalController::class, 'streamKtp'])
    ->name('admin.bookings.ktp.stream')
    ->middleware('signed');

// Temporary Signed URL for Protected Payment Proof Access (5 min expiry)
Route::get('/admin/payments/{id}/proof', [AdminPaymentController::class, 'streamProof'])
    ->name('admin.payments.proof.stream')
    ->middleware('signed');

// Temporary Signed URL for Protected Contract PDF Access (30 min expiry)
Route::get('/admin/contracts/{id}/stream', [ContractController::class, 'stream'])
    ->name('admin.contracts.stream')
    ->middleware('signed');

Route::get('/tenant/contracts/{id}/stream', [TenantContractController::class, 'stream'])
    ->name('tenant.contracts.stream')
    ->middleware('signed');


// Authenticated Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Tenant Mandatory Password Change (Accessible even if must_change_password is true)
    Route::post('/tenant/change-password', [TenantAuthController::class, 'changePassword']);

    // Tenant Portal Routes (Protected by EnsurePasswordHasBeenChanged)
    Route::prefix('tenant')->middleware(EnsurePasswordHasBeenChanged::class)->group(function () {
        Route::get('/profile', [TenantProfileController::class, 'profile']);

        // Invoices & Payments
        Route::get('/invoices', [TenantInvoiceController::class, 'index']);
        Route::get('/invoices/{id}', [TenantInvoiceController::class, 'show']);
        Route::post('/invoices/{id}/pay', [TenantPaymentController::class, 'pay']);
        Route::post('/invoices/{id}/manual-pay', [TenantPaymentController::class, 'manualPay']);
        Route::get('/invoices/{id}/payments', [TenantPaymentController::class, 'payments']);

        // Complaints
        Route::get('/complaints', [TenantComplaintController::class, 'index']);
        Route::post('/complaints', [TenantComplaintController::class, 'store']);

        // Contracts
        Route::get('/contract', [TenantContractController::class, 'show']);
        Route::post('/contract/sign', [TenantContractController::class, 'sign']);
    });

    // Admin Group
    Route::prefix('admin')->middleware(EnsureUserIsAdmin::class)->group(function () {
        // Facilities
        Route::get('/facilities', [FacilityController::class, 'index']);
        Route::post('/facilities', [FacilityController::class, 'store']);
        Route::delete('/facilities/{id}', [FacilityController::class, 'destroy']);

        // Rooms
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::get('/rooms/available', [RoomController::class, 'available']);
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::get('/rooms/{id}', [RoomController::class, 'show']);
        Route::put('/rooms/{id}', [RoomController::class, 'update']);
        Route::delete('/rooms/{id}', [RoomController::class, 'destroy']);

        // Tenancies
        Route::get('/tenancies', [TenancyController::class, 'index']);
        Route::post('/tenancies', [TenancyController::class, 'store']);
        Route::get('/tenancies/{id}', [TenancyController::class, 'show']);
        Route::post('/tenancies/{id}/checkout', [TenancyController::class, 'checkout']);

        // Contracts Management
        Route::post('/tenancies/{id}/contract', [ContractController::class, 'generate']);
        Route::get('/tenancies/{id}/contract', [ContractController::class, 'show']);

        // Invoices
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
        Route::patch('/invoices/{id}/status', [InvoiceController::class, 'updateStatus']);

        // Payments Management & Verification
        Route::get('/payments', [AdminPaymentController::class, 'index']);
        Route::get('/payments/{id}', [AdminPaymentController::class, 'show']);
        Route::patch('/payments/{id}/verify', [AdminPaymentController::class, 'verify']);

        // Bookings
        Route::get('/bookings', [BookingApprovalController::class, 'index']);
        Route::get('/bookings/{id}', [BookingApprovalController::class, 'show']);
        Route::patch('/bookings/{id}/approve', [BookingApprovalController::class, 'approve']);
        Route::patch('/bookings/{id}/reject', [BookingApprovalController::class, 'reject']);

        // Complaints Management
        Route::get('/complaints', [AdminComplaintController::class, 'index']);
        Route::get('/complaints/{id}', [AdminComplaintController::class, 'show']);
        Route::patch('/complaints/{id}', [AdminComplaintController::class, 'update']);

        // Financial Reports
        Route::get('/reports/income', [ReportController::class, 'income']);
        Route::get('/reports/export', [ReportController::class, 'export']);
    });

});
