<?php

use App\Http\Controllers\OpenWaMessageWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/internal/openwa/messages', [OpenWaMessageWebhookController::class, 'store'])
    ->name('internal.openwa.messages.store');
