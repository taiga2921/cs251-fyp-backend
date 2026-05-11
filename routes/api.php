<?php

use App\Http\Controllers\Api\AnprEventController;
use App\Http\Controllers\Api\AnprEventLogController;
use App\Http\Controllers\Api\AnprImageController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlockchainRecordController;
use App\Http\Controllers\Api\CameraController;
use App\Http\Controllers\Api\CheckpointController;
use App\Http\Controllers\Api\CheckpointEventController;
use App\Http\Controllers\Api\CheckpointEventMetricController;
use App\Http\Controllers\Api\LocationLogController;
use App\Http\Controllers\Api\PatrolRouteController;
use App\Http\Controllers\Api\PatrolSessionController;
use App\Http\Controllers\Api\PwaSyncController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('blockchain-records', BlockchainRecordController::class)->only(['index', 'show']);

    Route::middleware('admin')->group(function (): void {
        Route::apiResource('roles', RoleController::class)->only(['index', 'show']);
        Route::apiResource('users', UserController::class);
        Route::post('users/{user}/restore', [UserController::class, 'restore']);
    });

    Route::apiResource('cameras', CameraController::class);
    Route::apiResource('zones', ZoneController::class);
    Route::apiResource('checkpoints', CheckpointController::class);
    Route::apiResource('patrol-sessions', PatrolSessionController::class);
    Route::post('patrol-routes', [PatrolRouteController::class, 'store']);
    Route::apiResource('checkpoint-events', CheckpointEventController::class);
    Route::apiResource('checkpoint-event-metrics', CheckpointEventMetricController::class);
    Route::apiResource('location-logs', LocationLogController::class)
        ->only(['index', 'store', 'show', 'destroy']);
    Route::post('pwa/sync', [PwaSyncController::class, 'sync']);
    Route::apiResource('vehicles', VehicleController::class);
    Route::apiResource('anpr-events', AnprEventController::class);
    Route::apiResource('anpr-event-logs', AnprEventLogController::class);
    Route::apiResource('anpr-images', AnprImageController::class);
});
