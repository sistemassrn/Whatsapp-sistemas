<?php

use App\Http\Controllers\TestingAccessController;
use App\Http\Controllers\WhatsappConnectionController;
use App\Http\Controllers\WhatsappConversationsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('home'))->name('home');

Route::post('/testing-access', [TestingAccessController::class, 'store'])
    ->name('testing-access.store');

Route::post('/testing-access/logout', [TestingAccessController::class, 'destroy'])
    ->name('testing-access.destroy');

Route::get('/whatsapp/connect', [WhatsappConnectionController::class, 'index'])
    ->middleware('testing.access')
    ->name('whatsapp.connect');

Route::post('/whatsapp/connect/start', [WhatsappConnectionController::class, 'start'])
    ->middleware('testing.access')
    ->name('whatsapp.connect.start');

Route::get('/whatsapp/conversations', WhatsappConversationsController::class)
    ->middleware('testing.access')
    ->name('whatsapp.conversations');
