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

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::apiResource('branches', BranchController::class)->only(['index', 'show']);
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

    Route::apiResource('customers', CustomerController::class);
});
