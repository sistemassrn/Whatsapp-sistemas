<?php

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Auth\Middleware\Authenticate;
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
