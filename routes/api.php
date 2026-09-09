<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\BranchController;
use App\Http\Controllers\API\ProjectController;
use App\Http\Controllers\API\ProjectBlockController;
use App\Http\Controllers\API\PropertyController;
use App\Http\Controllers\API\PropertyImageController;
use App\Http\Controllers\API\PropertyDocumentController;
use App\Http\Controllers\API\PropertyFeatureController;
use App\Http\Controllers\API\PropertyStatusController;
use App\Http\Controllers\API\CustomerController;
use App\Http\Controllers\API\LeadController;
use App\Http\Controllers\API\SiteVisitController;
use App\Http\Controllers\API\BookingController;
use App\Http\Controllers\API\BookingStatusController;
use App\Http\Controllers\API\InstallmentPlanController;
use App\Http\Controllers\API\InstallmentController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\CommissionController;
use App\Http\Controllers\API\SalesDashboardController;
use App\Http\Controllers\API\ExpenseController;
use App\Http\Controllers\API\UserController;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Lightweight lookup used by CRM/sales forms. It does not expose user administration data.
    Route::get('sales-agents', [UserController::class, 'salesAgents'])->middleware('permission:sales.view');

    // User administration is protected by action-level permissions.
    Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');
    Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');

    // Reference data used by the authenticated application.
    Route::apiResource('branches', BranchController::class)->only(['index', 'show']);

    // Property and project management.
    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('project-blocks', ProjectBlockController::class);
    Route::get('properties/inventory', [PropertyController::class, 'inventory']);
    Route::apiResource('properties', PropertyController::class);
    Route::put('properties/{property}/status', [PropertyStatusController::class, 'update']);
    Route::get('properties/{property}/status-history', [PropertyStatusController::class, 'history']);
    Route::post('properties/{property}/images', [PropertyImageController::class, 'store']);
    Route::delete('property-images/{image}', [PropertyImageController::class, 'destroy']);
    Route::put('property-images/{image}/primary', [PropertyImageController::class, 'primary']);
    Route::post('properties/{property}/documents', [PropertyDocumentController::class, 'store']);
    Route::delete('property-documents/{document}', [PropertyDocumentController::class, 'destroy']);
    Route::post('properties/{property}/features', [PropertyFeatureController::class, 'store']);
    Route::delete('property-features/{feature}', [PropertyFeatureController::class, 'destroy']);

    // CRM and sales.
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('leads', LeadController::class);
    Route::apiResource('site-visits', SiteVisitController::class);
    Route::apiResource('bookings', BookingController::class);
    Route::post('bookings/{booking}/confirm', [BookingStatusController::class, 'confirm']);
    Route::post('bookings/{booking}/cancel', [BookingStatusController::class, 'cancel']);
    Route::post('bookings/{booking}/complete', [BookingStatusController::class, 'complete']);
    Route::get('sales/dashboard', [SalesDashboardController::class, 'index']);

    // Finance.
    Route::apiResource('installment-plans', InstallmentPlanController::class);
    Route::apiResource('installments', InstallmentController::class)->only(['index', 'show']);
    Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show']);
    Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt']);
    Route::apiResource('commissions', CommissionController::class)->only(['index', 'store', 'update']);
    Route::apiResource('expenses', ExpenseController::class);
});
