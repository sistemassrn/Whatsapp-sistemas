<?php

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('streams stored message media through an authenticated route', function () {
    $this->withoutMiddleware(Authenticate::class);

    Storage::fake('whatsapp_media');
    Storage::disk('whatsapp_media')->put('inbound/test/image.jpg', 'image-bytes');

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'body' => null,
        'type' => 'image',
        'status' => 'received',
        'media_disk' => 'whatsapp_media',
        'media_path' => 'inbound/test/image.jpg',
        'media_mime_type' => 'image/jpeg',
        'media_filename' => 'image.jpg',
        'media_size_bytes' => 11,
        'media_download_status' => 'stored',
    ]);

    $this->get(route('whatsapp.messages.media.show', $message))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('image-bytes', false);
});

it('returns not found safely when stored message media is missing', function () {
    $this->withoutMiddleware(Authenticate::class);

    Storage::fake('whatsapp_media');

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'body' => null,
        'type' => 'image',
        'status' => 'received',
        'media_disk' => 'whatsapp_media',
        'media_path' => 'inbound/test/missing.jpg',
        'media_mime_type' => 'image/jpeg',
        'media_filename' => 'missing.jpg',
        'media_download_status' => 'stored',
    ]);

    $this->get(route('whatsapp.messages.media.show', $message))
        ->assertNotFound();
});

it('stores inline webhook image media on the private disk', function () {
    Storage::fake('whatsapp_media');
    config(['openwa.webhook_secret' => null]);

    $this->postJson(route('internal.openwa.messages.store'), [
        'id' => 'webhook-image-1',
        'chatId' => '5491100000000@c.us',
        'from' => '5491100000000@c.us',
        'type' => 'image',
        'caption' => 'Foto recibida',
        'media' => [
            'mimetype' => 'image/png',
            'filename' => 'captura.png',
            'data' => base64_encode('png-bytes'),
        ],
    ])->assertOk()
        ->assertJson(['status' => 'stored']);

    $message = Message::query()->firstOrFail();

    expect($message->type)->toBe('image')
        ->and($message->body)->toBe('Foto recibida')
        ->and($message->media_disk)->toBe('whatsapp_media')
        ->and($message->media_mime_type)->toBe('image/png')
        ->and($message->media_filename)->toBe('captura.png')
        ->and($message->media_size_bytes)->toBe(strlen('png-bytes'))
        ->and($message->media_download_status)->toBe('stored');

    Storage::disk('whatsapp_media')->assertExists($message->media_path);
});

it('keeps webhook audio metadata when OpenWA omits the media payload', function () {
    Storage::fake('whatsapp_media');
    config(['openwa.webhook_secret' => null]);

    $this->postJson(route('internal.openwa.messages.store'), [
        'id' => 'webhook-audio-1',
        'chatId' => '5491100000000@c.us',
        'from' => '5491100000000@c.us',
        'type' => 'audio',
        'media' => [
            'mimetype' => 'audio/ogg',
            'filename' => 'voice.ogg',
            'omitted' => true,
            'duration' => 12,
        ],
    ])->assertOk();

    $message = Message::query()->firstOrFail();

    expect($message->type)->toBe('audio')
        ->and($message->media_path)->toBeNull()
        ->and($message->media_mime_type)->toBe('audio/ogg')
        ->and($message->media_filename)->toBe('voice.ogg')
        ->and($message->media_download_status)->toBe('omitted')
        ->and($message->media_metadata['duration'])->toBe(12);
});

it('downloads and stores omitted webhook image media from OpenWA', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.webhook_secret' => null,
    ]);

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5491100000000@c.us/wamid/image:1/media')) {
            return Http::response('downloaded-image', 200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'attachment; filename="foto.png"',
            ]);
        }

        return Http::response([], 404);
    });

    $this->postJson(route('internal.openwa.messages.store'), [
        'sessionId' => 'session-1',
        'id' => 'wamid/image:1',
        'chatId' => '5491100000000@c.us',
        'from' => '5491100000000@c.us',
        'type' => 'image',
        'media' => [
            'mimetype' => 'image/png',
            'filename' => 'foto.png',
            'omitted' => true,
        ],
    ])->assertOk();

    $message = Message::query()->firstOrFail();

    expect($message->media_download_status)->toBe('stored')
        ->and($message->media_mime_type)->toBe('image/png')
        ->and($message->media_filename)->toBe('foto.png')
        ->and($message->media_size_bytes)->toBe(strlen('downloaded-image'));

    Storage::disk('whatsapp_media')->assertExists($message->media_path);
    expect(Storage::disk('whatsapp_media')->get($message->media_path))->toBe('downloaded-image');
});

it('downloads omitted webhook media through the configured ready session when webhook has no session id', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.webhook_secret' => null,
    ]);

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5491100000000@c.us/wamid/no-session/media')) {
            return Http::response('downloaded-with-configured-session', 200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'attachment; filename="foto.png"',
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

    $this->postJson(route('internal.openwa.messages.store'), [
        'id' => 'wamid/no-session',
        'chatId' => '5491100000000@c.us',
        'from' => '5491100000000@c.us',
        'type' => 'image',
        'media' => [
            'mimetype' => 'image/png',
            'filename' => 'foto.png',
            'omitted' => true,
        ],
    ])->assertOk();

    $message = Message::query()->firstOrFail();

    expect($message->media_download_status)->toBe('stored')
        ->and($message->media_size_bytes)->toBe(strlen('downloaded-with-configured-session'));

    Storage::disk('whatsapp_media')->assertExists($message->media_path);
});

it('keeps omitted webhook media retriable when OpenWA cannot download it yet', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.webhook_secret' => null,
    ]);

    Http::fake([
        'openwa.test/api/sessions/session-1/messages/*' => Http::response(['message' => 'not ready'], 409),
    ]);

    $this->postJson(route('internal.openwa.messages.store'), [
        'sessionId' => 'session-1',
        'id' => 'webhook-image-failed-1',
        'chatId' => '5491100000000@c.us',
        'from' => '5491100000000@c.us',
        'type' => 'image',
        'media' => [
            'mimetype' => 'image/png',
            'filename' => 'foto.png',
            'omitted' => true,
        ],
    ])->assertOk();

    $message = Message::query()->firstOrFail();

    expect($message->media_path)->toBeNull()
        ->and($message->media_download_status)->toBe('omitted')
        ->and($message->media_error)->toBe('Archivo no disponible.');
});

it('downloads omitted media with the retry command', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.recent_sync_window_hours' => 24,
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-1',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'audio',
        'status' => 'received',
        'media_mime_type' => 'audio/ogg',
        'media_filename' => 'voice.ogg',
        'media_download_status' => 'omitted',
    ]);

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5491100000000@c.us/wamid-retry-1/media')) {
            return Http::response([
                'data' => base64_encode('retry-audio-bytes'),
                'mimetype' => 'audio/ogg',
                'filename' => 'voice.ogg',
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

    $this->artisan('whatsapp:retry-media --limit=10 --minutes=1440')
        ->expectsOutput('Intentados: 1')
        ->expectsOutput('Guardados: 1')
        ->assertSuccessful();

    $message = Message::query()->where('external_id', 'wamid-retry-1')->firstOrFail();

    expect($message->media_download_status)->toBe('stored')
        ->and($message->media_mime_type)->toBe('audio/ogg');

    Storage::disk('whatsapp_media')->assertExists($message->media_path);
    expect(Storage::disk('whatsapp_media')->get($message->media_path))->toBe('retry-audio-bytes');
});

it('does not retry omitted media outside the recent sync window', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.recent_sync_window_hours' => 24,
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-old',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'audio',
        'status' => 'received',
        'received_at' => now()->subHours(25),
        'media_mime_type' => 'audio/ogg',
        'media_filename' => 'old.ogg',
        'media_download_status' => 'omitted',
    ]);

    Message::withoutTimestamps(function () use ($message): void {
        $message->forceFill([
            'created_at' => now()->subHours(25),
            'updated_at' => now(),
        ])->save();
    });

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5491100000000@c.us/wamid-retry-old/media')) {
            return Http::response(['data' => base64_encode('old-audio-bytes')]);
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

    $this->artisan('whatsapp:retry-media --limit=10 --minutes=1440')
        ->expectsOutput('Intentados: 0')
        ->assertSuccessful();

    $message->refresh();

    expect($message->media_download_status)->toBe('omitted')
        ->and($message->media_path)->toBeNull();
});

it('stores webhook voice notes with ogg codec mime so they can be played', function () {
    Storage::fake('whatsapp_media');
    config(['openwa.webhook_secret' => null]);

    $this->postJson(route('internal.openwa.messages.store'), [
        'id' => 'webhook-voice-1',
        'chatId' => '5491100000000@c.us',
        'from' => '5491100000000@c.us',
        'type' => 'ptt',
        'media' => [
            'mimetype' => 'audio/ogg; codecs=opus',
            'filename' => 'voice.ogg',
            'data' => base64_encode('ogg-bytes'),
        ],
    ])->assertOk();

    $message = Message::query()->firstOrFail();

    expect($message->type)->toBe('audio')
        ->and($message->media_disk)->toBe('whatsapp_media')
        ->and($message->media_mime_type)->toBe('audio/ogg; codecs=opus')
        ->and($message->media_filename)->toBe('voice.ogg')
        ->and($message->media_download_status)->toBe('stored');

    Storage::disk('whatsapp_media')->assertExists($message->media_path);

    $this->withoutMiddleware(Authenticate::class);
    $this->get(route('whatsapp.messages.media.show', $message))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/ogg; codecs=opus');
});

it('downloads omitted webhook voice notes with ogg codec mime so they can be played', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.webhook_secret' => null,
    ]);

    Http::fake([
        'openwa.test/api/sessions/session-1/messages/*' => Http::response([
            'data' => base64_encode('ogg-bytes'),
            'mimetype' => 'audio/ogg; codecs=opus',
            'filename' => 'voice.ogg',
        ]),
    ]);

    $this->postJson(route('internal.openwa.messages.store'), [
        'sessionId' => 'session-1',
        'id' => 'webhook-voice-download-1',
        'chatId' => '5491100000000@c.us',
        'from' => '5491100000000@c.us',
        'type' => 'ptt',
        'media' => [
            'mimetype' => 'audio/ogg; codecs=opus',
            'filename' => 'voice.ogg',
            'omitted' => true,
        ],
    ])->assertOk();

    $message = Message::query()->firstOrFail();

    expect($message->type)->toBe('audio')
        ->and($message->media_download_status)->toBe('stored')
        ->and($message->media_mime_type)->toBe('audio/ogg; codecs=opus');

    $this->withoutMiddleware(Authenticate::class);
    $this->get(route('whatsapp.messages.media.show', $message))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/ogg; codecs=opus')
        ->assertSee('ogg-bytes', false);
});

it('stores webhook media as failed when base64 payload is invalid', function () {
    Storage::fake('whatsapp_media');
    config(['openwa.webhook_secret' => null]);

    $this->postJson(route('internal.openwa.messages.store'), [
        'id' => 'webhook-invalid-media-1',
        'chatId' => '5491100000000@c.us',
        'from' => '5491100000000@c.us',
        'type' => 'image',
        'caption' => 'Foto dañada',
        'media' => [
            'mimetype' => 'image/png',
            'filename' => 'captura.png',
            'data' => '%%%no-es-base64%%%',
        ],
    ])->assertOk()
        ->assertJson(['status' => 'stored']);

    $message = Message::query()->firstOrFail();

    expect($message->type)->toBe('image')
        ->and($message->body)->toBe('Foto dañada')
        ->and($message->media_path)->toBeNull()
        ->and($message->media_mime_type)->toBe('image/png')
        ->and($message->media_download_status)->toBe('failed')
        ->and($message->media_error)->toBe('No se pudo decodificar el contenido multimedia.');
});
