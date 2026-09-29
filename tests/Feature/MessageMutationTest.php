<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Services\OpenWaClient;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Http;

it('edits an outbound text message through OpenWA and updates the local copy', function () {
    $this->withoutMiddleware(Authenticate::class);

    fakeReadyOpenWa();

    $conversation = createConversation();
    $message = createMessage($conversation, [
        'body' => 'Texto original',
    ]);

    $response = $this->patch(route('whatsapp.messages.update', $message), [
        'body' => 'Texto editado',
    ]);

    $response->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));
    $response->assertSessionHas('success', 'Mensaje editado.');

    $message->refresh();

    expect($message->body)->toBe('Texto editado')
        ->and($message->remote_edit_status)->toBe('accepted')
        ->and($message->edit_error)->toBeNull()
        ->and($message->edited_at)->not->toBeNull();

    Http::assertSent(fn ($request): bool => $request->url() === 'http://openwa.test/api/sessions/session-1/messages/edit'
        && $request['chatId'] === $conversation->external_id
        && $request['messageId'] === 'openwa-message-1'
        && $request['body'] === 'Texto editado');
});

it('marks edit failure without changing the local body when OpenWA rejects it', function () {
    $this->withoutMiddleware(Authenticate::class);

    fakeReadyOpenWa([
        'openwa.test/api/sessions/session-1/messages/edit' => Http::response([
            'message' => 'Message openwa-message-1 not found',
        ], 404),
    ]);

    $conversation = createConversation();
    $message = createMessage($conversation, [
        'body' => 'Texto original',
    ]);

    $response = $this->patch(route('whatsapp.messages.update', $message), [
        'body' => 'Texto editado',
    ]);

    $response->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));
    $response->assertSessionHas('error', 'No encontramos el mensaje para modificarlo.');

    $message->refresh();

    expect($message->body)->toBe('Texto original')
        ->and($message->remote_edit_status)->toBe('failed')
        ->and($message->edit_error)->toBe('No encontramos el mensaje para modificarlo.')
        ->and($message->edited_at)->toBeNull();
});

it('deletes an outbound text message through OpenWA and keeps a local tombstone', function () {
    $this->withoutMiddleware(Authenticate::class);

    fakeReadyOpenWa();

    $conversation = createConversation();
    $message = createMessage($conversation, [
        'body' => 'Se elimina',
    ]);

    $response = $this->delete(route('whatsapp.messages.destroy', $message));

    $response->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));
    $response->assertSessionHas('success', 'Mensaje eliminado.');

    $message->refresh();

    expect($message->body)->toBe('Se elimina')
        ->and($message->deleted_at)->not->toBeNull()
        ->and($message->remote_delete_status)->toBe('accepted')
        ->and($message->delete_error)->toBeNull();

    Http::assertSent(fn ($request): bool => $request->url() === 'http://openwa.test/api/sessions/session-1/messages/delete'
        && $request['chatId'] === $conversation->external_id
        && $request['messageId'] === 'openwa-message-1'
        && $request['forEveryone'] === true);
});

it('does not allow editing inbound or media messages', function () {
    $this->withoutMiddleware(Authenticate::class);

    Http::fake();

    $conversation = createConversation();
    $inboundMessage = createMessage($conversation, [
        'direction' => 'inbound',
        'external_id' => 'inbound-message-1',
        'status' => 'received',
    ]);
    $mediaMessage = createMessage($conversation, [
        'type' => 'image',
        'external_id' => 'image-message-1',
    ]);

    $this->patch(route('whatsapp.messages.update', $inboundMessage), [
        'body' => 'No permitido',
    ])->assertSessionHas('error', 'Solo se pueden editar mensajes enviados por el operador.');

    $this->patch(route('whatsapp.messages.update', $mediaMessage), [
        'body' => 'No permitido',
    ])->assertSessionHas('error', 'Por ahora solo se pueden editar mensajes de texto.');

    Http::assertNothingSent();
});

it('sends exact OpenWA payload keys from the client', function () {
    config()->set('openwa.base_url', 'http://openwa.test/api');

    Http::fake([
        'openwa.test/api/sessions/session-1/messages/edit' => Http::response(['messageId' => 'openwa-message-1']),
        'openwa.test/api/sessions/session-1/messages/delete' => Http::response(['success' => true]),
    ]);

    $client = app(OpenWaClient::class);

    $client->editMessage('session-1', '5491100000000@c.us', 'openwa-message-1', 'Editado');
    $client->deleteMessage('session-1', '5491100000000@c.us', 'openwa-message-1');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://openwa.test/api/sessions/session-1/messages/edit'
        && $request->data() === [
            'chatId' => '5491100000000@c.us',
            'messageId' => 'openwa-message-1',
            'body' => 'Editado',
        ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://openwa.test/api/sessions/session-1/messages/delete'
        && $request->data() === [
            'chatId' => '5491100000000@c.us',
            'messageId' => 'openwa-message-1',
            'forEveryone' => true,
        ]);
});

function fakeReadyOpenWa(array $overrides = []): void
{
    config()->set('openwa.base_url', 'http://openwa.test/api');
    config()->set('openwa.session_name', 'whatsapp-sistemas');

    Http::fake(array_merge([
        'openwa.test/api/sessions/session-1/messages/edit' => Http::response([
            'messageId' => 'openwa-message-1',
        ]),
        'openwa.test/api/sessions/session-1/messages/delete' => Http::response([
            'success' => true,
        ]),
        'openwa.test/api/sessions*' => Http::response([
            'data' => [
                'id' => 'session-1',
                'name' => 'whatsapp-sistemas',
                'status' => 'ready',
            ],
        ]),
    ], $overrides));
}

function createConversation(): Conversation
{
    return Conversation::query()->create([
        'external_id' => '5491100000000@c.us',
        'title' => 'Cliente Demo',
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createMessage(Conversation $conversation, array $attributes = []): Message
{
    return Message::query()->create(array_merge([
        'conversation_id' => $conversation->id,
        'external_id' => 'openwa-message-1',
        'direction' => 'outbound',
        'body' => 'Hola',
        'type' => 'text',
        'status' => 'accepted',
        'sent_at' => now(),
    ], $attributes));
}
