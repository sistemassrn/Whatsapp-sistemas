<?php

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Http;

it('keeps a hidden conversation hidden when an existing message is revoked', function () {
    config(['openwa.webhook_secret' => null]);
    $hiddenAt = now()->subHour()->startOfSecond();
    $conversation = Conversation::query()->create([
        'external_id' => '5491100000001@c.us',
        'hidden_at' => $hiddenAt,
        'hidden_reason' => 'user_hidden',
        'unread_count' => 2,
    ]);
    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'existing-revoked-message',
        'direction' => 'inbound',
        'body' => 'Mensaje anterior',
        'status' => 'received',
        'received_at' => $hiddenAt->copy()->subMinute(),
    ]);
    $conversation->update(['last_message_id' => $message->id, 'last_message_at' => $message->received_at]);

    $this->postJson(route('internal.openwa.messages.store'), [
        'event' => 'message.revoked',
        'data' => [
            'revokedId' => $message->external_id,
            'deleted' => true,
            'revokedAt' => now()->timestamp,
        ],
    ])->assertOk()->assertJson(['status' => 'stored']);

    expect($conversation->fresh())->hidden_at->toEqual($hiddenAt)
        ->hidden_reason->toBe('user_hidden')
        ->unread_count->toBe(2)
        ->last_message_id->toBe($message->id)
        ->and($message->fresh()->deleted_at)->not->toBeNull();
});

it('does not create a conversation or tombstone for an orphan revoke webhook', function () {
    config(['openwa.webhook_secret' => null]);

    $this->postJson(route('internal.openwa.messages.store'), [
        'event' => 'message.revoked',
        'data' => [
            'id' => 'revoke-notice',
            'revokedId' => 'missing-original-message',
            'chatId' => '5491100000002@c.us',
            'deleted' => true,
            'timestamp' => now()->timestamp,
        ],
    ])->assertOk()->assertJson([
        'status' => 'ignored',
        'reason' => 'orphan_mutation',
    ]);

    expect(Conversation::query()->where('external_id', '5491100000002@c.us')->exists())->toBeFalse()
        ->and(Message::query()->where('external_id', 'missing-original-message')->exists())->toBeFalse();
});

it('keeps a hidden conversation hidden for an old replayed webhook message', function () {
    config(['openwa.webhook_secret' => null]);
    $hiddenAt = now()->startOfSecond();
    $conversation = Conversation::query()->create([
        'external_id' => '5491100000003@c.us',
        'hidden_at' => $hiddenAt,
        'hidden_reason' => 'user_hidden',
        'unread_count' => 3,
    ]);

    $this->postJson(route('internal.openwa.messages.store'), reactivationWebhookPayload('old-replayed-message', $conversation->external_id, $hiddenAt->copy()->subSecond()->timestamp))
        ->assertOk()->assertJson(['status' => 'stored']);

    expect($conversation->fresh())->hidden_at->toEqual($hiddenAt)
        ->hidden_reason->toBe('user_hidden')
        ->unread_count->toBe(3);
});

it('unhides a hidden conversation for a newer ordinary webhook message', function () {
    config(['openwa.webhook_secret' => null]);
    $conversation = Conversation::query()->create([
        'external_id' => '5491100000004@c.us',
        'hidden_at' => now()->subHour(),
        'hidden_reason' => 'user_hidden',
    ]);

    $this->postJson(route('internal.openwa.messages.store'), reactivationWebhookPayload('newer-message', $conversation->external_id, now()->timestamp))
        ->assertOk()->assertJson(['status' => 'stored']);

    expect($conversation->fresh())->hidden_at->toBeNull()
        ->hidden_reason->toBeNull()
        ->unread_count->toBe(1);
});

it('keeps a hidden conversation hidden when a webhook message has no remote timestamp', function () {
    config(['openwa.webhook_secret' => null]);
    $hiddenAt = now()->subHour();
    $conversation = Conversation::query()->create([
        'external_id' => '5491100000005@c.us',
        'hidden_at' => $hiddenAt,
        'hidden_reason' => 'user_hidden',
        'unread_count' => 1,
    ]);
    $payload = reactivationWebhookPayload('timestamp-less-message', $conversation->external_id, now()->timestamp);
    unset($payload['data']['timestamp']);

    $this->postJson(route('internal.openwa.messages.store'), $payload)
        ->assertOk()->assertJson(['status' => 'stored']);

    expect($conversation->fresh())->hidden_at->not->toBeNull()
        ->hidden_reason->toBe('user_hidden')
        ->unread_count->toBe(1);
});

it('unhides a hidden conversation when an operator sends a local message', function () {
    $this->withoutMiddleware(Authenticate::class);
    config([
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
    ]);
    Http::fake([
        'openwa.test/api/sessions/session-1/messages/send-text' => Http::response(['messageId' => 'local-unhide-message']),
        'openwa.test/api/sessions*' => Http::response(['data' => ['id' => 'session-1', 'status' => 'ready']]),
    ]);
    $conversation = Conversation::query()->create([
        'external_id' => '5491100000006@c.us',
        'hidden_at' => now(),
        'hidden_reason' => 'user_hidden',
    ]);

    $this->post(route('whatsapp.conversations.messages.store', $conversation), [
        'body' => 'Mensaje del operador',
        'idempotency_key' => 'local-unhide-key',
    ])->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));

    expect($conversation->fresh())->hidden_at->toBeNull()
        ->hidden_reason->toBeNull();
});

/**
 * @return array<string, mixed>
 */
function reactivationWebhookPayload(string $messageId, string $chatId, int $timestamp): array
{
    return [
        'event' => 'message.received',
        'data' => [
            'id' => $messageId,
            'chatId' => $chatId,
            'from' => $chatId,
            'body' => 'Mensaje remoto',
            'timestamp' => $timestamp,
        ],
    ];
}
