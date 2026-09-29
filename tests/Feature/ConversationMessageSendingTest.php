<?php

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('stores an outbound message and sends it to OpenWA', function () {
    $this->withoutMiddleware(Authenticate::class);

    config()->set('openwa.base_url', 'http://openwa.test/api');
    config()->set('openwa.session_name', 'whatsapp-sistemas');

    Http::fake([
        'openwa.test/api/sessions/session-1/messages/send-text' => Http::response([
            'messageId' => 'openwa-message-1',
        ]),
        'openwa.test/api/sessions*' => Http::response([
            'data' => [
                'id' => 'session-1',
                'name' => 'whatsapp-sistemas',
                'status' => 'ready',
            ],
        ]),
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $response = $this
        ->post(route('whatsapp.conversations.messages.store', $conversation), [
            'body' => 'Hola desde Laravel',
            'idempotency_key' => 'send-key-1',
        ]);

    $response->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));

    $message = Message::query()->firstOrFail();

    expect($message->direction)->toBe('outbound')
        ->and($message->body)->toBe('Hola desde Laravel')
        ->and($message->status)->toBe('accepted')
        ->and($message->external_id)->toBe('openwa-message-1')
        ->and($message->sent_at)->not->toBeNull();

    $conversation->refresh();

    expect($conversation->last_message_id)->toBe($message->id)
        ->and($conversation->last_message_at)->not->toBeNull();

    Http::assertSent(fn ($request): bool => $request->url() === 'http://openwa.test/api/sessions/session-1/messages/send-text'
        && $request['chatId'] === '5491100000000@c.us'
        && $request['text'] === 'Hola desde Laravel');
});

it('stores an outbound media message and sends base64 to OpenWA', function () {
    $this->withoutMiddleware(Authenticate::class);

    Storage::fake('whatsapp_media');
    config()->set('openwa.base_url', 'http://openwa.test/api');
    config()->set('openwa.session_name', 'whatsapp-sistemas');

    Http::fake([
        'openwa.test/api/sessions/session-1/messages/send-image' => Http::response([
            'messageId' => 'openwa-media-1',
        ]),
        'openwa.test/api/sessions*' => Http::response([
            'data' => [
                'id' => 'session-1',
                'name' => 'whatsapp-sistemas',
                'status' => 'ready',
            ],
        ]),
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $file = UploadedFile::fake()->image('foto.jpg', 20, 20);

    $response = $this
        ->post(route('whatsapp.conversations.messages.store', $conversation), [
            'body' => 'Mirá esta foto',
            'media' => [$file],
            'idempotency_key' => 'send-media-key-1',
        ]);

    $response->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));

    $message = Message::query()->firstOrFail();

    expect($message->type)->toBe('image')
        ->and($message->body)->toBe('Mirá esta foto')
        ->and($message->status)->toBe('accepted')
        ->and($message->external_id)->toBe('openwa-media-1')
        ->and($message->media_disk)->toBe('whatsapp_media')
        ->and($message->media_download_status)->toBe('stored');

    Storage::disk('whatsapp_media')->assertExists($message->media_path);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://openwa.test/api/sessions/session-1/messages/send-image'
        && $request['chatId'] === '5491100000000@c.us'
        && $request['mimetype'] === 'image/jpeg'
        && $request['caption'] === 'Mirá esta foto'
        && is_string($request['base64'])
        && $request['base64'] !== '');
});

it('rejects unsupported outbound media with a Spanish validation error', function () {
    $this->withoutMiddleware(Authenticate::class);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $file = UploadedFile::fake()->create('malware.exe', 1, 'application/x-msdownload');

    $response = $this
        ->from(route('whatsapp.conversations', ['chat' => $conversation->external_id]))
        ->post(route('whatsapp.conversations.messages.store', $conversation), [
            'media' => [$file],
            'idempotency_key' => 'send-invalid-media-key-1',
        ]);

    $response->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));
    $response->assertSessionHasErrors([
        'media.0' => 'Tipo de archivo no permitido.',
    ]);

    expect(Message::query()->count())->toBe(0);
});

it('sends up to three media files as separate messages with derived idempotency keys', function () {
    $this->withoutMiddleware(Authenticate::class);

    Storage::fake('whatsapp_media');
    config()->set('openwa.base_url', 'http://openwa.test/api');
    config()->set('openwa.session_name', 'whatsapp-sistemas');

    Http::fake([
        'openwa.test/api/sessions/session-1/messages/send-document' => Http::sequence()
            ->push(['messageId' => 'openwa-doc-1'])
            ->push(['messageId' => 'openwa-doc-2'])
            ->push(['messageId' => 'openwa-doc-3']),
        'openwa.test/api/sessions*' => Http::response([
            'data' => [
                'id' => 'session-1',
                'name' => 'whatsapp-sistemas',
                'status' => 'ready',
            ],
        ]),
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $response = $this
        ->post(route('whatsapp.conversations.messages.store', $conversation), [
            'body' => 'Adjuntos',
            'media' => [
                UploadedFile::fake()->create('consulta.sql', 1, 'text/plain'),
                UploadedFile::fake()->create('notas.rs', 1, 'text/plain'),
                UploadedFile::fake()->create('datos.int', 1, 'application/octet-stream'),
            ],
            'idempotency_key' => 'send-files-key',
        ]);

    $response->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));

    $messages = Message::query()->orderBy('id')->get();

    expect($messages)->toHaveCount(3)
        ->and($messages[0]->body)->toBe('Adjuntos')
        ->and($messages[1]->body)->toBeNull()
        ->and($messages[2]->body)->toBeNull()
        ->and($messages->pluck('idempotency_key')->all())->toBe([
            'send-files-key-1',
            'send-files-key-2',
            'send-files-key-3',
        ])
        ->and($messages->pluck('status')->all())->toBe(['accepted', 'accepted', 'accepted']);

    Http::assertSentCount(4);
});

it('rejects more than three media files', function () {
    $this->withoutMiddleware(Authenticate::class);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $response = $this
        ->from(route('whatsapp.conversations', ['chat' => $conversation->external_id]))
        ->post(route('whatsapp.conversations.messages.store', $conversation), [
            'media' => [
                UploadedFile::fake()->create('a.txt', 1, 'text/plain'),
                UploadedFile::fake()->create('b.txt', 1, 'text/plain'),
                UploadedFile::fake()->create('c.txt', 1, 'text/plain'),
                UploadedFile::fake()->create('d.txt', 1, 'text/plain'),
            ],
            'idempotency_key' => 'send-too-many-key',
        ]);

    $response->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));
    $response->assertSessionHasErrors([
        'media' => 'Podés enviar hasta 3 archivos por vez.',
    ]);

    expect(Message::query()->count())->toBe(0);
});

it('marks outbound messages as failed when OpenWA rejects the send', function () {
    $this->withoutMiddleware(Authenticate::class);

    config()->set('openwa.base_url', 'http://openwa.test/api');
    config()->set('openwa.session_name', 'whatsapp-sistemas');

    Http::fake([
        'openwa.test/api/sessions/session-1/messages/send-text' => Http::response([
            'message' => 'chatId es obligatorio',
        ], 422),
        'openwa.test/api/sessions*' => Http::response([
            'data' => [
                'id' => 'session-1',
                'name' => 'whatsapp-sistemas',
                'status' => 'ready',
            ],
        ]),
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $response = $this
        ->post(route('whatsapp.conversations.messages.store', $conversation), [
            'body' => 'Hola desde Laravel',
            'idempotency_key' => 'send-openwa-error-key-1',
        ]);

    $response->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));
    $response->assertSessionHas('error', 'Revisá el destinatario, el texto o el adjunto.');

    $message = Message::query()->firstOrFail();

    expect($message->status)->toBe('failed')
        ->and($message->error_message)->toBe('Revisá el destinatario, el texto o el adjunto.');
});

it('does not duplicate messages for the same idempotency key', function () {
    $this->withoutMiddleware(Authenticate::class);

    config()->set('openwa.base_url', 'http://openwa.test/api');

    Http::fake([
        'openwa.test/api/*' => Http::response([
            'data' => [
                'id' => 'session-1',
                'status' => 'ready',
            ],
        ]),
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'body' => 'Mensaje existente',
        'status' => 'accepted',
        'idempotency_key' => 'send-key-1',
        'sent_at' => now(),
    ]);

    $response = $this
        ->post(route('whatsapp.conversations.messages.store', $conversation), [
            'body' => 'Mensaje duplicado',
            'idempotency_key' => 'send-key-1',
        ]);

    $response->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));

    expect(Message::query()->count())->toBe(1);

    Http::assertNothingSent();
});
