<?php

use Glitter\Connect\Http\Controllers\ConnectController;
use Illuminate\Support\Facades\Route;

Route::post('inbound/{endpointKey}', [ConnectController::class, 'inbound'])->middleware('throttle:30,1')->name('inbound');

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->name('admin.')->group(function () {
    Route::get('overview', [ConnectController::class, 'overview'])->middleware('permission:admin,glitter-connect.connect.read');
    Route::get('connections', [ConnectController::class, 'connections'])->middleware('permission:admin,glitter-connect.connect.read');
    Route::post('connections', [ConnectController::class, 'createConnection'])->middleware('permission:admin,glitter-connect.connect.manage');
    Route::get('connections/{id}', [ConnectController::class, 'connection'])->whereNumber('id')->middleware('permission:admin,glitter-connect.connect.read');
    Route::patch('connections/{id}', [ConnectController::class, 'updateConnection'])->whereNumber('id')->middleware('permission:admin,glitter-connect.connect.manage');
    Route::delete('connections/{id}', [ConnectController::class, 'deleteConnection'])->whereNumber('id')->middleware('permission:admin,glitter-connect.connect.manage');
    Route::post('connections/{id}/test', [ConnectController::class, 'testConnection'])->whereNumber('id')->middleware('permission:admin,glitter-connect.connect.manage');
    Route::post('connections/{id}/rotate-secret', [ConnectController::class, 'rotateSecret'])->whereNumber('id')->middleware('permission:admin,glitter-connect.connect.manage');
    Route::get('endpoints', [ConnectController::class, 'endpoints'])->middleware('permission:admin,glitter-connect.connect.read');
    Route::post('endpoints', [ConnectController::class, 'createEndpoint'])->middleware('permission:admin,glitter-connect.connect.manage');
    Route::patch('endpoints/{id}', [ConnectController::class, 'updateEndpoint'])->whereNumber('id')->middleware('permission:admin,glitter-connect.connect.manage');
    Route::get('subscriptions', [ConnectController::class, 'subscriptions'])->middleware('permission:admin,glitter-connect.connect.read');
    Route::post('subscriptions', [ConnectController::class, 'createSubscription'])->middleware('permission:admin,glitter-connect.connect.manage');
    Route::patch('subscriptions/{id}', [ConnectController::class, 'updateSubscription'])->whereNumber('id')->middleware('permission:admin,glitter-connect.connect.manage');
    Route::get('events', [ConnectController::class, 'events'])->middleware('permission:admin,glitter-connect.connect.read');
    Route::get('events/{id}', [ConnectController::class, 'event'])->whereNumber('id')->middleware('permission:admin,glitter-connect.connect.read');
    Route::get('deliveries', [ConnectController::class, 'deliveries'])->middleware('permission:admin,glitter-connect.connect.read');
    Route::get('deliveries/{id}', [ConnectController::class, 'delivery'])->whereNumber('id')->middleware('permission:admin,glitter-connect.connect.read');
    Route::post('deliveries/{id}/replay', [ConnectController::class, 'replay'])->whereNumber('id')->middleware('permission:admin,glitter-connect.deliveries.replay');
    Route::get('metrics', [ConnectController::class, 'metrics'])->middleware('permission:admin,glitter-connect.connect.read');
    Route::get('settings', [ConnectController::class, 'settings'])->middleware('permission:admin,glitter-connect.connect.read');
    Route::put('settings', [ConnectController::class, 'updateSettings'])->middleware('permission:admin,glitter-connect.settings.manage');
});
