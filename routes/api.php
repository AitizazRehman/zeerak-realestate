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


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {

    Route::post('/login', [
        AuthController::class,
        'login'
    ]);
});


/*
|--------------------------------------------------------------------------
| Protected API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {


    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    Route::prefix('auth')->group(function () {

        Route::get('/me', [
            AuthController::class,
            'me'
        ]);

        Route::post('/logout', [
            AuthController::class,
            'logout'
        ]);
    });


    /*
    |--------------------------------------------------------------------------
    | Branches / Offices
    |--------------------------------------------------------------------------
    */

    Route::apiResource('branches', BranchController::class)
        ->only([
            'index',
            'show'
        ]);


    /*
    |--------------------------------------------------------------------------
    | Projects
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'projects',
        ProjectController::class
    );


    /*
    |--------------------------------------------------------------------------
    | Project Blocks
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'project-blocks',
        ProjectBlockController::class
    );


    /*
    |--------------------------------------------------------------------------
    | Properties
    |--------------------------------------------------------------------------
    */

    Route::get(
        'properties/inventory',
        [
            PropertyController::class,
            'inventory'
        ]
    );

    Route::apiResource(
        'properties',
        PropertyController::class
    );


    /*
    |--------------------------------------------------------------------------
    | Property Status
    |--------------------------------------------------------------------------
    */

    Route::put(
        'properties/{property}/status',
        [
            PropertyStatusController::class,
            'update'
        ]
    );

    Route::get(
        'properties/{property}/status-history',
        [
            PropertyStatusController::class,
            'history'
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | Property Images
    |--------------------------------------------------------------------------
    */

    Route::post(
        'properties/{property}/images',
        [
            PropertyImageController::class,
            'store'
        ]
    );

    Route::delete(
        'property-images/{image}',
        [
            PropertyImageController::class,
            'destroy'
        ]
    );
    Route::put(
        'property-images/{image}/primary',
        [PropertyImageController::class, 'primary']
    );


    /*
    |--------------------------------------------------------------------------
    | Property Documents
    |--------------------------------------------------------------------------
    */

    Route::post(
        'properties/{property}/documents',
        [
            PropertyDocumentController::class,
            'store'
        ]
    );

    Route::delete(
        'property-documents/{document}',
        [
            PropertyDocumentController::class,
            'destroy'
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | Property Features
    |--------------------------------------------------------------------------
    */

    Route::post(
        'properties/{property}/features',
        [
            PropertyFeatureController::class,
            'store'
        ]
    );

    Route::delete(
        'property-features/{feature}',
        [
            PropertyFeatureController::class,
            'destroy'
        ]
    );
});
