<?php

use App\Modules\Analytics\Http\Controllers\AnalyticsOpenApiController;
use App\Modules\Analytics\Http\Controllers\AnalyticsStripeWebhookController;
use App\Modules\Analytics\Http\Controllers\Collection\CollectEventsController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/openapi.json', AnalyticsOpenApiController::class)->name('api.openapi');
Route::middleware('throttle:analytics.collect')->post('/v1/collect/{publicId}', CollectEventsController::class)
    ->where('publicId', '[A-Za-z0-9]+')
    ->name('api.collect');
Route::middleware('throttle:analytics.collect')->options('/v1/collect/{publicId}', [CollectEventsController::class, 'preflight'])
    ->where('publicId', '[A-Za-z0-9]+')
    ->name('api.collect.preflight');

if (config('analytics.billing.webhooks_enabled', false)) {
    Route::post('/billing/stripe/webhook', AnalyticsStripeWebhookController::class)
        ->middleware('throttle:120,1')
        ->name('api.billing.stripe-webhook');
}
