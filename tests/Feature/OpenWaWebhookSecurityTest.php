<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsappMessageMediaDownloader;

it('rejects OpenWA webhooks when a configured shared secret is missing from the request', function () {
    config([
        'openwa.webhook_secret' => 'local-secret-123456',
        'openwa.require_webhook_secret' => true,
    ]);

    $this->postJson(route('internal.openwa.messages.store'), webhookPayload())
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden']);

    expect(Message::query()->count())->toBe(0);
});

it('accepts OpenWA webhooks with the configured custom shared secret header', function () {
    config([
        'openwa.webhook_secret' => 'local-secret-123456',
        'openwa.require_webhook_secret' => true,
    ]);

    $this->withHeader('X-Webhook-Secret', 'local-secret-123456')
        ->postJson(route('internal.openwa.messages.store'), webhookPayload('secret-header-1'))
        ->assertOk()
        ->assertJson(['status' => 'stored']);

    expect(Message::query()->where('external_id', 'secret-header-1')->exists())->toBeTrue();
});

it('accepts OpenWA webhooks signed with X-OpenWA-Signature', function () {
    config([
        'openwa.webhook_secret' => 'local-secret-123456',
        'openwa.require_webhook_secret' => true,
    ]);

    $content = json_encode(webhookPayload('signed-webhook-1'), JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $content, 'local-secret-123456');

    $this->call('POST', route('internal.openwa.messages.store'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_OPENWA_SIGNATURE' => $signature,
    ], $content)
        ->assertOk()
        ->assertJson(['status' => 'stored']);

    expect(Message::query()->where('external_id', 'signed-webhook-1')->exists())->toBeTrue();
});

it('fails closed in production mode when webhook secret is required but not configured', function () {
    config([
        'openwa.webhook_secret' => null,
        'openwa.require_webhook_secret' => true,
    ]);

    $this->postJson(route('internal.openwa.messages.store'), webhookPayload())
        ->assertServiceUnavailable()
        ->assertJson(['message' => 'Webhook secret is not configured']);
});

it('ignores old unknown OpenWA webhook messages without creating records', function () {
    config([
        'openwa.import_unknown_historical_chats' => false,
        'openwa.import_max_age_days' => 240,
        'openwa.require_webhook_secret' => false,
    ]);

    $payload = webhookPayload('old-unknown-webhook-1', [
        'chatId' => '5491199990000@c.us',
        'from' => '5491199990000@c.us',
        'timestamp' => now()->subDays(241)->timestamp,
    ]);

    $this->postJson(route('internal.openwa.messages.store'), $payload)
        ->assertOk()
        ->assertJson([
            'status' => 'ignored',
            'reason' => 'historical_unknown_chat',
        ]);

    expect(Conversation::query()->where('external_id', '5491199990000@c.us')->exists())->toBeFalse()
        ->and(Message::query()->where('external_id', 'old-unknown-webhook-1')->exists())->toBeFalse();
});

it('stores new unknown OpenWA webhook messages', function () {
    config([
        'openwa.import_unknown_historical_chats' => false,
        'openwa.require_webhook_secret' => false,
    ]);

    $this->postJson(route('internal.openwa.messages.store'), webhookPayload('new-unknown-webhook-1', [
        'chatId' => '5491199990001@c.us',
        'from' => '5491199990001@c.us',
        'timestamp' => now()->timestamp,
    ]))
        ->assertOk()
        ->assertJson(['status' => 'stored']);

    expect(Conversation::query()->where('external_id', '5491199990001@c.us')->exists())->toBeTrue()
        ->and(Message::query()->where('external_id', 'new-unknown-webhook-1')->exists())->toBeTrue();
});

it('stores omitted media webhook messages without attempting synchronous media download', function () {
    config([
        'openwa.import_unknown_historical_chats' => false,
        'openwa.require_webhook_secret' => false,
    ]);

    $this->mock(WhatsappMessageMediaDownloader::class)
        ->shouldNotReceive('attempt');

    $this->postJson(route('internal.openwa.messages.store'), webhookPayload('omitted-media-webhook-1', [
        'type' => 'image',
        'mimetype' => 'image/jpeg',
        'filename' => 'foto.jpg',
        'mediaDataMissing' => true,
    ]))
        ->assertOk()
        ->assertJson(['status' => 'stored']);

    $message = Message::query()->where('external_id', 'omitted-media-webhook-1')->firstOrFail();

    expect($message->media_download_status)->toBe('omitted')
        ->and($message->media_path)->toBeNull();
});

it('stores OpenWA webhook messages without timestamps', function () {
    config([
        'openwa.import_unknown_historical_chats' => false,
        'openwa.require_webhook_secret' => false,
    ]);

    $payload = webhookPayload('missing-timestamp-webhook-1', [
        'chatId' => '5491199990002@c.us',
        'from' => '5491199990002@c.us',
    ]);
    unset($payload['data']['timestamp']);

    $this->postJson(route('internal.openwa.messages.store'), $payload)
        ->assertOk()
        ->assertJson(['status' => 'stored']);

    expect(Conversation::query()->where('external_id', '5491199990002@c.us')->exists())->toBeTrue()
        ->and(Message::query()->where('external_id', 'missing-timestamp-webhook-1')->exists())->toBeTrue();
});

it('stores old saved contact OpenWA webhook messages', function () {
    config([
        'openwa.import_unknown_historical_chats' => false,
        'openwa.import_max_age_days' => 240,
        'openwa.require_webhook_secret' => false,
    ]);

    $payload = webhookPayload('old-saved-webhook-1', [
        'chatId' => '5491199990003@c.us',
        'from' => '5491199990003@c.us',
        'timestamp' => now()->subDays(241)->timestamp,
        'contact' => [
            'id' => '5491199990003@c.us',
            'isMyContact' => true,
            'pushName' => 'Cliente Guardado',
        ],
    ]);

    $this->postJson(route('internal.openwa.messages.store'), $payload)
        ->assertOk()
        ->assertJson(['status' => 'stored']);

    expect(Conversation::query()->where('external_id', '5491199990003@c.us')->exists())->toBeTrue()
        ->and(Message::query()->where('external_id', 'old-saved-webhook-1')->exists())->toBeTrue();
});

it('reconciles fromMe webhook messages with recent local verifying outbound messages', function () {
    config([
        'openwa.import_unknown_historical_chats' => false,
        'openwa.require_webhook_secret' => false,
    ]);

    $conversation = Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);

    $localMessage = Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'body' => 'Mensaje pendiente de confirmar',
        'type' => 'text',
        'status' => 'verifying',
        'error_message' => 'No pudimos confirmar el envío. Lo estamos verificando.',
        'sent_at' => now(),
    ]);

    $this->postJson(route('internal.openwa.messages.store'), webhookPayload('from-me-reconcile-1', [
        'fromMe' => true,
        'from' => 'me@c.us',
        'to' => '5491100000000@c.us',
        'body' => 'Mensaje pendiente de confirmar',
        'timestamp' => now()->timestamp,
    ]))
        ->assertOk()
        ->assertJson([
            'status' => 'stored',
            'message_id' => $localMessage->id,
        ]);

    expect(Message::query()->count())->toBe(1);

    $localMessage->refresh();

    expect($localMessage->external_id)->toBe('from-me-reconcile-1')
        ->and($localMessage->status)->toBe('accepted')
        ->and($localMessage->error_message)->toBeNull();
});

/**
 * @return array<string, mixed>
 */
function webhookPayload(string $id = 'secret-webhook-1', array $messageOverrides = []): array
{
    return [
        'event' => 'message.received',
        'data' => array_merge([
            'id' => $id,
            'chatId' => '5491100000000@c.us',
            'from' => '5491100000000@c.us',
            'fromMe' => false,
            'type' => 'text',
            'body' => 'Mensaje con secreto verificado',
            'timestamp' => now()->timestamp,
        ], $messageOverrides),
    ];
}
