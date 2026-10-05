<?php

use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\MessageMediaController;
use App\Http\Controllers\MessageMutationController;
use App\Http\Controllers\NexoAuthController;
use App\Http\Controllers\WhatsappConnectionController;
use App\Http\Controllers\WhatsappContactAvatarController;
use App\Http\Controllers\WhatsappConversationsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [NexoAuthController::class, 'create'])->name('home');
Route::get('/login', [NexoAuthController::class, 'create'])->name('login');
Route::post('/login', [NexoAuthController::class, 'store'])->name('login.store');
Route::post('/logout', [NexoAuthController::class, 'destroy'])->name('logout');

Route::get('/whatsapp/connect', [WhatsappConnectionController::class, 'index'])
    ->middleware('auth')
    ->name('whatsapp.connect');

Route::post('/whatsapp/connect/start', [WhatsappConnectionController::class, 'start'])
    ->middleware('auth')
    ->name('whatsapp.connect.start');

Route::post('/whatsapp/disconnect', [WhatsappConnectionController::class, 'disconnect'])
    ->middleware('auth')
    ->name('whatsapp.disconnect');

Route::get('/whatsapp/conversations', WhatsappConversationsController::class)
    ->middleware('auth')
    ->name('whatsapp.conversations');

Route::post('/whatsapp/conversations/{conversation}/mark-unread', [WhatsappConversationsController::class, 'markUnread'])
    ->middleware('auth')
    ->name('whatsapp.conversations.mark-unread');

Route::post('/whatsapp/conversations/{conversation}/hide', [WhatsappConversationsController::class, 'hide'])
    ->middleware('auth')
    ->name('whatsapp.conversations.hide');

Route::post('/whatsapp/contacts/{contact}/avatar', WhatsappContactAvatarController::class)
    ->middleware('auth')
    ->name('whatsapp.contacts.avatar');

Route::post('/whatsapp/conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])
    ->middleware('auth')
    ->name('whatsapp.conversations.messages.store');

Route::get('/whatsapp/messages/{message}/media', [MessageMediaController::class, 'show'])
    ->middleware('auth')
    ->name('whatsapp.messages.media.show');

Route::patch('/whatsapp/messages/{message}', [MessageMutationController::class, 'update'])
    ->middleware('auth')
    ->name('whatsapp.messages.update');

Route::delete('/whatsapp/messages/{message}', [MessageMutationController::class, 'destroy'])
    ->middleware('auth')
    ->name('whatsapp.messages.destroy');
