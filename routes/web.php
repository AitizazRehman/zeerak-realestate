<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SpaController;

Route::get('/', [SpaController::class, 'index']);

Route::get('/{any}', [SpaController::class, 'index'])
    ->where('any', '^(?!api).*$');
