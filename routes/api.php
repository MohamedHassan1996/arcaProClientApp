<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\MaintenanceRequestController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\PeriodicMaintenanceController;
use App\Http\Controllers\Api\V1\PeriodicMaintenanceStatsController;
use App\Http\Controllers\Api\V1\ProductMaintenanceHistoryController;

Route::prefix('v1/')->group(function () {

    Route::controller(AuthController::class)->prefix('auth')->group(function () {
        Route::post('/login','login');
        Route::post('/logout','logout');
    });

    Route::prefix('periodic-maintenances')->group(function(){
        Route::get('', [PeriodicMaintenanceController::class, 'index']);
    });


    Route::prefix('periodic-maintenance-stats')->group(function(){
        Route::get('', [PeriodicMaintenanceStatsController::class, 'index']);
    });

    Route::prefix('product-maintenance-history')->group(function(){
        Route::get('', [ProductMaintenanceHistoryController::class, 'index']);
    });

    Route::prefix('maintenance-request')->group(function(){
        Route::post('', [MaintenanceRequestController::class, 'store']);
    });


});
