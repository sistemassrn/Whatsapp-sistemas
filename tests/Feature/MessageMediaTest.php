<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Services\OpenWaClient;
use App\Services\WhatsappMessageMediaDownloader;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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
        'openwa.session_name' => 'whatsapp-sistemas',
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

    expect($message->media_download_status)->toBe('omitted');

    $this->artisan('whatsapp:retry-media --limit=10 --minutes=1440')
        ->assertSuccessful();

    $message->refresh();

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

    expect($message->media_download_status)->toBe('omitted');

    $this->artisan('whatsapp:retry-media --limit=10 --minutes=1440')
        ->assertSuccessful();

    $message->refresh();

    expect($message->media_download_status)->toBe('stored')
        ->and($message->media_size_bytes)->toBe(strlen('downloaded-with-configured-session'));

    Storage::disk('whatsapp_media')->assertExists($message->media_path);
});

it('keeps omitted webhook media retriable when OpenWA cannot download it yet', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.webhook_secret' => null,
    ]);

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/')) {
            return Http::response(['message' => 'not ready'], 409);
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
        ->and($message->media_error)->toBeNull();

    $this->artisan('whatsapp:retry-media --limit=10 --minutes=1440')
        ->assertSuccessful();

    expect($message->refresh()->media_path)->toBeNull()
        ->and($message->media_download_status)->toBe('omitted')
        ->and($message->media_error)->toBe('Archivo no disponible.');
});

it('downloads omitted media with the retry command', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.retry_media_window_hours' => 240,
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
        'sent_at' => now()->subHours(2),
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

it('retries stored media with the retry command when the local file is missing', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.retry_media_window_hours' => 240,
        'openwa.retry_media_cooldown_hours' => 6,
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-missing-file',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'image',
        'status' => 'received',
        'sent_at' => now()->subHour(),
        'media_disk' => 'whatsapp_media',
        'media_path' => 'inbound/test/missing.jpg',
        'media_mime_type' => 'image/jpeg',
        'media_filename' => 'missing.jpg',
        'media_download_status' => 'stored',
    ]);

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5491100000000@c.us/wamid-retry-missing-file/media')) {
            return Http::response([
                'data' => base64_encode('recovered-image-bytes'),
                'mimetype' => 'image/jpeg',
                'filename' => 'recovered.jpg',
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

    $message = Message::query()->where('external_id', 'wamid-retry-missing-file')->firstOrFail();

    expect($message->media_download_status)->toBe('stored')
        ->and($message->media_error)->toBeNull()
        ->and($message->media_path)->not->toBe('inbound/test/missing.jpg');

    Storage::disk('whatsapp_media')->assertExists($message->media_path);
    expect(Storage::disk('whatsapp_media')->get($message->media_path))->toBe('recovered-image-bytes');
});

it('skips stored media with the retry command when the local file exists', function () {
    Storage::fake('whatsapp_media');
    Storage::disk('whatsapp_media')->put('inbound/test/existing.jpg', 'existing-image-bytes');
    config([
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.retry_media_window_hours' => 240,
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-existing-file',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'image',
        'status' => 'received',
        'sent_at' => now()->subHour(),
        'media_disk' => 'whatsapp_media',
        'media_path' => 'inbound/test/existing.jpg',
        'media_mime_type' => 'image/jpeg',
        'media_filename' => 'existing.jpg',
        'media_download_status' => 'stored',
    ]);

    app()->instance(OpenWaClient::class, new class extends OpenWaClient
    {
        public function findSessionByName(string $name): ?array
        {
            return [
                'id' => 'session-1',
                'name' => $name,
                'status' => 'ready',
            ];
        }
    });
    app()->instance(WhatsappMessageMediaDownloader::class, new class(app(OpenWaClient::class)) extends WhatsappMessageMediaDownloader
    {
        public function attempt(Message $message, string $sessionId, ?string $chatId = null, array $context = []): bool
        {
            throw new RuntimeException('Existing stored media should not be retried.');
        }
    });

    $this->artisan('whatsapp:retry-media --limit=10 --minutes=1440')
        ->expectsOutput('Intentados: 0')
        ->expectsOutput('Omitidos: 1')
        ->assertSuccessful();

    expect($message->refresh()->media_download_status)->toBe('stored')
        ->and($message->media_path)->toBe('inbound/test/existing.jpg');
});

it('skips retriable media scheduled for a future retry', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.retry_media_window_hours' => 240,
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-future-error',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'image',
        'status' => 'received',
        'sent_at' => now()->subHour(),
        'media_download_status' => 'failed',
        'media_error' => 'Archivo no disponible.',
        'media_next_retry_at' => now()->addMinutes(10),
    ]);

    app()->instance(OpenWaClient::class, new class extends OpenWaClient
    {
        public function findSessionByName(string $name): ?array
        {
            return [
                'id' => 'session-1',
                'name' => $name,
                'status' => 'ready',
            ];
        }
    });
    app()->instance(WhatsappMessageMediaDownloader::class, new class(app(OpenWaClient::class)) extends WhatsappMessageMediaDownloader
    {
        public function attempt(Message $message, string $sessionId, ?string $chatId = null, array $context = []): bool
        {
            throw new RuntimeException('Future scheduled media should not be retried.');
        }
    });

    $this->artisan('whatsapp:retry-media --limit=10 --minutes=1440')
        ->expectsOutput('Intentados: 0')
        ->assertSuccessful();

    expect($message->refresh()->media_download_status)->toBe('failed')
        ->and($message->media_next_retry_at)->not->toBeNull();
});

it('retries media with missing retry schedule even when updated recently', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.retry_media_window_hours' => 240,
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $nullScheduleMessage = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-null-schedule',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'image',
        'status' => 'received',
        'sent_at' => now()->subHour(),
        'media_download_status' => 'failed',
        'media_error' => 'Archivo no disponible.',
        'media_next_retry_at' => null,
    ]);

    Message::withoutTimestamps(function () use ($nullScheduleMessage): void {
        $nullScheduleMessage->forceFill(['updated_at' => now()])->save();
    });

    app()->instance(OpenWaClient::class, new class extends OpenWaClient
    {
        public function findSessionByName(string $name): ?array
        {
            return [
                'id' => 'session-1',
                'name' => $name,
                'status' => 'ready',
            ];
        }
    });
    app()->instance(WhatsappMessageMediaDownloader::class, new class(app(OpenWaClient::class)) extends WhatsappMessageMediaDownloader
    {
        public function attempt(Message $message, string $sessionId, ?string $chatId = null, array $context = []): bool
        {
            $message->forceFill([
                'media_download_status' => 'stored',
                'media_error' => null,
            ])->save();

            return true;
        }
    });

    expect(Artisan::call('whatsapp:retry-media', [
        '--limit' => 1,
        '--minutes' => 1440,
    ]))->toBe(0);

    expect(Artisan::output())->toContain('Intentados: 1');

    expect($nullScheduleMessage->refresh()->media_download_status)->toBe('stored');
});

it('retries media with due retry schedule even when updated recently', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.retry_media_window_hours' => 240,
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-past-schedule',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'image',
        'status' => 'received',
        'sent_at' => now()->subHours(2),
        'media_download_status' => 'failed',
        'media_error' => 'Archivo no disponible.',
        'media_next_retry_at' => now()->subDay(),
    ]);

    Message::withoutTimestamps(function () use ($message): void {
        $message->forceFill(['updated_at' => now()])->save();
    });

    app()->instance(OpenWaClient::class, new class extends OpenWaClient
    {
        public function findSessionByName(string $name): ?array
        {
            return [
                'id' => 'session-1',
                'name' => $name,
                'status' => 'ready',
            ];
        }
    });
    app()->instance(WhatsappMessageMediaDownloader::class, new class(app(OpenWaClient::class)) extends WhatsappMessageMediaDownloader
    {
        public function attempt(Message $message, string $sessionId, ?string $chatId = null, array $context = []): bool
        {
            $message->forceFill([
                'media_download_status' => 'stored',
                'media_error' => null,
            ])->save();

            return true;
        }
    });

    expect(Artisan::call('whatsapp:retry-media', [
        '--limit' => 1,
        '--minutes' => 1440,
    ]))->toBe(0);

    expect(Artisan::output())->toContain('Intentados: 1');

    expect($message->refresh()->media_download_status)->toBe('stored');
});

it('sets retriable media backoff after failure and clears it after success', function () {
    Storage::fake('whatsapp_media');
    Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00'));

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-backoff',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'image',
        'status' => 'received',
        'sent_at' => now()->subMinute(),
        'media_download_status' => 'omitted',
    ]);

    $failedDownloader = new WhatsappMessageMediaDownloader(new class extends OpenWaClient
    {
        public function downloadMessageMedia(string $sessionId, string $chatId, string $messageId): ?array
        {
            return null;
        }

        public function downloadMessageMediaFromHistory(string $sessionId, string $chatId, string $messageId, int $limit = 20): ?array
        {
            return null;
        }
    });

    expect($failedDownloader->attempt($message, 'session-1', '5491100000000@c.us'))->toBeFalse();

    $message->refresh();

    expect($message->media_download_status)->toBe('omitted')
        ->and($message->media_error)->toBe('Archivo no disponible.')
        ->and($message->media_retry_attempts)->toBe(1)
        ->and($message->media_next_retry_at?->equalTo(now()->addMinutes(5)))->toBeTrue();

    $successfulDownloader = new WhatsappMessageMediaDownloader(new class extends OpenWaClient
    {
        public function downloadMessageMedia(string $sessionId, string $chatId, string $messageId): ?array
        {
            return [
                'binary' => 'image-bytes',
                'mimetype' => 'image/jpeg',
                'filename' => 'image.jpg',
            ];
        }
    });

    expect($successfulDownloader->attempt($message->refresh(), 'session-1', '5491100000000@c.us'))->toBeTrue();

    $message->refresh();

    expect($message->media_download_status)->toBe('stored')
        ->and($message->media_error)->toBeNull()
        ->and($message->media_next_retry_at)->toBeNull()
        ->and($message->media_retry_attempts)->toBe(0);

    Carbon::setTestNow();
});

it('excludes permanent media type errors from the retry command', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.retry_media_window_hours' => 240,
        'openwa.retry_media_cooldown_hours' => 6,
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-permanent-error',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'image',
        'status' => 'received',
        'sent_at' => now()->subHour(),
        'media_download_status' => 'failed',
        'media_error' => 'Tipo de archivo no permitido.',
    ]);

    Message::withoutTimestamps(function () use ($message): void {
        $message->forceFill(['updated_at' => now()->subHours(7)])->save();
    });

    app()->instance(OpenWaClient::class, new class extends OpenWaClient
    {
        public function findSessionByName(string $name): ?array
        {
            return [
                'id' => 'session-1',
                'name' => $name,
                'status' => 'ready',
            ];
        }
    });
    app()->instance(WhatsappMessageMediaDownloader::class, new class(app(OpenWaClient::class)) extends WhatsappMessageMediaDownloader
    {
        public function attempt(Message $message, string $sessionId, ?string $chatId = null, array $context = []): bool
        {
            throw new RuntimeException('Permanent media errors should not be retried.');
        }
    });

    $this->artisan('whatsapp:retry-media --limit=10 --minutes=1440')
        ->expectsOutput('Intentados: 0')
        ->assertSuccessful();

    expect($message->refresh()->media_download_status)->toBe('failed')
        ->and($message->media_error)->toBe('Tipo de archivo no permitido.');
});

it('does not retry omitted media outside the retry media window', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.recent_sync_window_hours' => 24,
        'openwa.retry_media_window_hours' => 240,
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
        'received_at' => now()->subHours(241),
        'media_mime_type' => 'audio/ogg',
        'media_filename' => 'old.ogg',
        'media_download_status' => 'omitted',
    ]);

    Message::withoutTimestamps(function () use ($message): void {
        $message->forceFill([
            'created_at' => now()->subHours(241),
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

it('retries recent media before older media regardless of updated at order', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.retry_media_window_hours' => 240,
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $olderMessage = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-order-old',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'audio',
        'status' => 'received',
        'sent_at' => now()->subHours(2),
        'media_mime_type' => 'audio/ogg',
        'media_filename' => 'old.ogg',
        'media_download_status' => 'omitted',
    ]);
    $recentMessage = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-retry-order-recent',
        'direction' => 'inbound',
        'body' => null,
        'type' => 'audio',
        'status' => 'received',
        'sent_at' => now()->subHour(),
        'media_mime_type' => 'audio/ogg',
        'media_filename' => 'recent.ogg',
        'media_download_status' => 'omitted',
    ]);

    app()->instance(OpenWaClient::class, new class extends OpenWaClient
    {
        public function findSessionByName(string $name): ?array
        {
            return [
                'id' => 'session-1',
                'name' => $name,
                'status' => 'ready',
            ];
        }
    });
    app()->instance(WhatsappMessageMediaDownloader::class, new class(app(OpenWaClient::class)) extends WhatsappMessageMediaDownloader
    {
        public function attempt(Message $message, string $sessionId, ?string $chatId = null, array $context = []): bool
        {
            $message->forceFill(['media_download_status' => 'stored'])->save();

            return true;
        }
    });

    expect(Artisan::call('whatsapp:retry-media', [
        '--limit' => 1,
        '--minutes' => 1440,
    ]))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Intentados: 1')
        ->and($output)->toContain('Guardados: 1');

    expect($recentMessage->refresh()->media_download_status)->toBe('stored')
        ->and($olderMessage->refresh()->media_download_status)->toBe('omitted')
        ->and($olderMessage->media_path)->toBeNull();
});

it('keeps omitted webhook media retriable when direct and history downloads return null', function () {
    Storage::fake('whatsapp_media');
    config([
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.webhook_secret' => null,
    ]);

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/5491100000000@c.us/webhook-image-null-1/media')) {
            return Http::response([], 404);
        }

        if (str_contains($url, '/sessions/session-1/messages/5491100000000@c.us/history')) {
            return Http::response(['data' => []]);
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
        'sessionId' => 'session-1',
        'id' => 'webhook-image-null-1',
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
        ->and($message->media_error)->toBeNull();

    $this->artisan('whatsapp:retry-media --limit=10 --minutes=1440')
        ->assertSuccessful();

    expect($message->refresh()->media_path)->toBeNull()
        ->and($message->media_download_status)->toBe('omitted')
        ->and($message->media_error)->toBe('Archivo no disponible.');
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
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.webhook_secret' => null,
    ]);

    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/messages/')) {
            return Http::response([
                'data' => base64_encode('ogg-bytes'),
                'mimetype' => 'audio/ogg; codecs=opus',
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
        ->and($message->media_download_status)->toBe('omitted');

    $this->artisan('whatsapp:retry-media --limit=10 --minutes=1440')
        ->assertSuccessful();

    $message->refresh();

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
