<?php

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.recent_sync_window_hours' => 24,
    ]);
});

it('exits successfully without creating messages when the session is not ready', function () {
    Http::fake([
        'openwa.test/api/sessions*' => Http::response([
            'data' => [
                'id' => 'session-1',
                'name' => 'whatsapp-sistemas',
                'status' => 'qr_ready',
            ],
        ]),
    ]);

    $this->artisan('whatsapp:sync-recent')
        ->expectsOutput('La sesión no está lista. Estado actual: qr_ready.')
        ->assertSuccessful();

    expect(Message::query()->count())->toBe(0);
});

it('imports a missing message and skips the duplicate on the second run', function () {
    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5491111111111@c.us/history')) {
            return Http::response([
                'data' => [[
                    'id' => 'wamid-1',
                    'chatId' => '5491111111111@c.us',
                    'fromMe' => false,
                    'body' => 'Hola desde el celular',
                    'timestamp' => now()->timestamp,
                ]],
            ]);
        }

        if (str_contains($url, '/sessions/session-1/chats')) {
            return Http::response([
                'data' => [[
                    'id' => '5491111111111@c.us',
                    'name' => 'Cliente Nuevo',
                    'contact' => [
                        'id' => '5491111111111@c.us',
                        'pushName' => 'Cliente',
                    ],
                ]],
            ]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('whatsapp:sync-recent')
        ->assertSuccessful();

    $this->artisan('whatsapp:sync-recent')
        ->assertSuccessful();

    expect(Message::query()->where('external_id', 'wamid-1')->count())->toBe(1)
        ->and(Message::query()->where('external_id', 'wamid-1')->first())
        ->body->toBe('Hola desde el celular')
        ->direction->toBe('inbound')
        ->status->toBe('received');
});

it('updates an existing message when recent history reports an external edit or delete', function () {
    $conversation = Conversation::query()->create([
        'external_id' => '5497777777777@c.us',
        'title' => 'Cliente Mutación',
    ]);

    $editedMessage = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-edited-sync',
        'direction' => 'inbound',
        'body' => 'Antes de editar',
        'status' => 'received',
        'received_at' => now(),
    ]);

    $deletedMessage = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-deleted-sync',
        'direction' => 'inbound',
        'body' => 'Antes de eliminar',
        'status' => 'received',
        'received_at' => now(),
    ]);

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5497777777777@c.us/history')) {
            return Http::response([
                'data' => [
                    [
                        'id' => 'wamid-edited-sync',
                        'chatId' => '5497777777777@c.us',
                        'fromMe' => false,
                        'body' => 'Después de editar',
                        'edited' => true,
                        'timestamp' => now()->timestamp,
                    ],
                    [
                        'id' => 'wamid-deleted-sync',
                        'chatId' => '5497777777777@c.us',
                        'fromMe' => false,
                        'body' => 'Antes de eliminar',
                        'deleted' => true,
                        'timestamp' => now()->timestamp,
                    ],
                ],
            ]);
        }

        if (str_contains($url, '/sessions/session-1/chats')) {
            return Http::response([
                'data' => [[
                    'id' => '5497777777777@c.us',
                    'name' => 'Cliente Mutación',
                ]],
            ]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('whatsapp:sync-recent')->assertSuccessful();

    $editedMessage->refresh();
    $deletedMessage->refresh();

    expect($editedMessage->body)->toBe('Después de editar')
        ->and($editedMessage->edited_at)->not->toBeNull()
        ->and($deletedMessage->deleted_at)->not->toBeNull();
});

it('updates the conversation last message after syncing recent history', function () {
    $conversation = Conversation::query()->create([
        'external_id' => '5492222222222@c.us',
        'title' => 'Cliente Existente',
    ]);

    $oldMessage = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-old',
        'direction' => 'inbound',
        'body' => 'Mensaje viejo',
        'status' => 'received',
        'received_at' => now()->subYears(2),
    ]);

    $conversation->forceFill([
        'last_message_id' => $oldMessage->id,
        'last_message_at' => $oldMessage->received_at,
    ])->save();

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5492222222222@c.us/history')) {
            return Http::response([
                'data' => [[
                    'id' => 'wamid-new',
                    'chatId' => '5492222222222@c.us',
                    'fromMe' => true,
                    'body' => 'Respuesta nueva',
                    'timestamp' => now()->timestamp,
                ]],
            ]);
        }

        if (str_contains($url, '/sessions/session-1/chats')) {
            return Http::response([
                'data' => [[
                    'id' => '5492222222222@c.us',
                    'name' => 'Cliente Existente',
                ]],
            ]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('whatsapp:sync-recent')->assertSuccessful();

    $conversation->refresh();

    expect($conversation->lastMessage)
        ->not->toBeNull()
        ->external_id->toBe('wamid-new')
        ->body->toBe('Respuesta nueva');
});

it('downloads omitted media while syncing recent history', function () {
    Storage::fake('whatsapp_media');

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5493333333333@c.us/history')) {
            return Http::response([
                'data' => [[
                    'id' => 'wamid-sync-image-1',
                    'chatId' => '5493333333333@c.us',
                    'fromMe' => false,
                    'type' => 'image',
                    'media' => [
                        'mimetype' => 'image/jpeg',
                        'filename' => 'sync.jpg',
                        'omitted' => true,
                    ],
                    'timestamp' => now()->timestamp,
                ]],
            ]);
        }

        if (str_contains($url, '/sessions/session-1/messages/5493333333333@c.us/wamid-sync-image-1/media')) {
            return Http::response([
                'data' => base64_encode('sync-image-bytes'),
                'mimetype' => 'image/jpeg',
                'filename' => 'sync.jpg',
            ]);
        }

        if (str_contains($url, '/sessions/session-1/chats')) {
            return Http::response([
                'data' => [[
                    'id' => '5493333333333@c.us',
                    'name' => 'Cliente Media',
                ]],
            ]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('whatsapp:sync-recent')->assertSuccessful();

    $message = Message::query()->where('external_id', 'wamid-sync-image-1')->firstOrFail();

    expect($message->media_download_status)->toBe('stored')
        ->and($message->media_mime_type)->toBe('image/jpeg')
        ->and($message->media_filename)->toBe('sync.jpg');

    Storage::disk('whatsapp_media')->assertExists($message->media_path);
    expect(Storage::disk('whatsapp_media')->get($message->media_path))->toBe('sync-image-bytes');
});

it('skips chats whose chat and message activity are older than the recent sync window', function () {
    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5494444444444@c.us/history')) {
            return Http::response([
                'data' => [[
                    'id' => 'wamid-old-sync',
                    'chatId' => '5494444444444@c.us',
                    'fromMe' => false,
                    'body' => 'Mensaje viejo',
                    'timestamp' => now()->subHours(25)->timestamp,
                ]],
            ]);
        }

        if (str_contains($url, '/sessions/session-1/chats')) {
            return Http::response([
                'data' => [[
                    'id' => '5494444444444@c.us',
                    'name' => 'Cliente Viejo',
                    'timestamp' => now()->subHours(25)->timestamp,
                ]],
            ]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('whatsapp:sync-recent')->assertSuccessful();

    expect(Conversation::query()->where('external_id', '5494444444444@c.us')->exists())->toBeFalse()
        ->and(Message::query()->where('external_id', 'wamid-old-sync')->exists())->toBeFalse();
});

it('keeps saved contacts even when their messages are older than the recent sync window', function () {
    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5496666666666@c.us/history')) {
            return Http::response([
                'data' => [[
                    'id' => 'wamid-old-saved-sync',
                    'chatId' => '5496666666666@c.us',
                    'fromMe' => false,
                    'body' => 'Mensaje viejo guardado',
                    'timestamp' => now()->subHours(25)->timestamp,
                ]],
            ]);
        }

        if (str_contains($url, '/sessions/session-1/chats')) {
            return Http::response([
                'data' => [[
                    'id' => '5496666666666@c.us',
                    'name' => 'Cliente Guardado',
                    'isSaved' => true,
                    'timestamp' => now()->subHours(25)->timestamp,
                    'contact' => [
                        'id' => '5496666666666@c.us',
                        'pushName' => 'Guardado',
                    ],
                ]],
            ]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('whatsapp:sync-recent')->assertSuccessful();

    $conversation = Conversation::query()->where('external_id', '5496666666666@c.us')->firstOrFail();

    expect($conversation->title)->toBe('Cliente Guardado')
        ->and($conversation->contact)->not->toBeNull()
        ->and(Message::query()->where('external_id', 'wamid-old-saved-sync')->exists())->toBeFalse();
});

it('skips unknown historical chats during recent sync', function () {
    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5499999999999@c.us/history')) {
            return Http::response([
                'data' => [[
                    'id' => 'wamid-unknown-sync',
                    'chatId' => '5499999999999@c.us',
                    'fromMe' => false,
                    'body' => 'Mensaje técnico sin contacto',
                    'timestamp' => now()->timestamp,
                ]],
            ]);
        }

        if (str_contains($url, '/sessions/session-1/chats')) {
            return Http::response([
                'data' => [[
                    'id' => '5499999999999@c.us',
                    'title' => '5499999999999@c.us',
                ]],
            ]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('whatsapp:sync-recent')->assertSuccessful();

    expect(Conversation::query()->where('external_id', '5499999999999@c.us')->exists())->toBeFalse()
        ->and(Message::query()->where('external_id', 'wamid-unknown-sync')->exists())->toBeFalse();
});

it('increments unread count for newly imported inbound messages', function () {
    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5495555555555@c.us/history')) {
            return Http::response([
                'data' => [[
                    'id' => 'wamid-unread-sync',
                    'chatId' => '5495555555555@c.us',
                    'fromMe' => false,
                    'body' => 'Mensaje no leído',
                    'timestamp' => now()->timestamp,
                ]],
            ]);
        }

        if (str_contains($url, '/sessions/session-1/chats')) {
            return Http::response([
                'data' => [[
                    'id' => '5495555555555@c.us',
                    'name' => 'Cliente No Leído',
                ]],
            ]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('whatsapp:sync-recent')->assertSuccessful();

    $conversation = Conversation::query()->where('external_id', '5495555555555@c.us')->firstOrFail();

    expect($conversation->unread_count)->toBe(1);
});
