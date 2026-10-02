<?php

use App\Models\Message;

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

/**
 * @return array<string, mixed>
 */
function webhookPayload(string $id = 'secret-webhook-1'): array
{
    return [
        'event' => 'message.received',
        'data' => [
            'id' => $id,
            'chatId' => '5491100000000@c.us',
            'from' => '5491100000000@c.us',
            'fromMe' => false,
            'type' => 'text',
            'body' => 'Mensaje con secreto verificado',
            'timestamp' => now()->timestamp,
        ],
    ];
}
