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
                    'timestamp' => 1_761_234_567,
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
                    'timestamp' => 1_761_234_567,
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
                    'timestamp' => 1_761_234_567,
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
