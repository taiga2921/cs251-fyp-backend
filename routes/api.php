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
use App\Http\Controllers\Api\PushNotificationController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\PwaSyncController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function (): void {
    Route::post('broadcasting/auth', function (Request $request) {
        return Broadcast::auth($request);
    });

    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('blockchain-records', BlockchainRecordController::class)->only(['index', 'show']);

    Route::middleware('admin')->group(function (): void {
        Route::apiResource('roles', RoleController::class)->only(['index', 'show']);
        Route::apiResource('users', UserController::class);
        Route::post('users/{user}/restore', [UserController::class, 'restore']);
        Route::apiResource('vehicles', VehicleController::class);
    });

    Route::apiResource('cameras', CameraController::class);
    Route::apiResource('zones', ZoneController::class);
    Route::apiResource('checkpoints', CheckpointController::class);
    Route::get('patrol-sessions/{patrol_session}/summary', [PatrolSessionController::class, 'summary'])
        ->name('patrol-sessions.summary');
    Route::post('patrol-sessions/{patrol_session}/validate', [PatrolSessionController::class, 'validateSession'])
        ->name('patrol-sessions.validate');
    Route::apiResource('patrol-sessions', PatrolSessionController::class);
    Route::get('patrol-routes', [PatrolRouteController::class, 'index']);
    Route::post('patrol-routes', [PatrolRouteController::class, 'store']);
    Route::apiResource('checkpoint-events', CheckpointEventController::class);
    Route::apiResource('checkpoint-event-metrics', CheckpointEventMetricController::class);
    Route::apiResource('location-logs', LocationLogController::class)
        ->only(['index', 'store', 'show']);
    Route::post('pwa/sync', [PwaSyncController::class, 'sync']);
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('push-subscriptions/{push_subscription}', [PushSubscriptionController::class, 'destroy']);
    Route::post('push-notifications/test', [PushNotificationController::class, 'test']);
    Route::apiResource('anpr-events', AnprEventController::class);
    Route::post('anpr-events/{anpr_event}/images/upload', [AnprImageController::class, 'uploadForEvent'])
        ->name('anpr-events.images.upload');
    Route::apiResource('anpr-event-logs', AnprEventLogController::class);
    Route::get('anpr-images/{anpr_image}/file', [AnprImageController::class, 'file'])
        ->name('anpr-images.file');
    Route::apiResource('anpr-images', AnprImageController::class);
});
