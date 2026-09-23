<?php

use App\Modules\Analytics\Http\Controllers\Collection\CollectEventsController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:analytics.collect')->post('/v1/collect/{publicId}', CollectEventsController::class)
    ->where('publicId', '[A-Za-z0-9]+');
Route::middleware('throttle:analytics.collect')->options('/v1/collect/{publicId}', [CollectEventsController::class, 'preflight'])
    ->where('publicId', '[A-Za-z0-9]+');
