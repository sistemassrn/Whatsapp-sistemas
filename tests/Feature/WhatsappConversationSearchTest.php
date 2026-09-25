<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\OpenWaClient;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Client\ConnectionException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['openwa.api_key' => 'testing-key']);
});

it('filters conversations by chat search', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    bindReadyOpenWaClient();

    $matchingContact = Contact::query()->create([
        'external_id' => '5491111111111@c.us',
        'name' => 'Cliente Buscado',
        'push_name' => 'Alias Uno',
        'phone' => '5491111111111',
    ]);

    $matchingConversation = Conversation::query()->create([
        'external_id' => '5491111111111@c.us',
        'contact_id' => $matchingContact->id,
        'title' => 'Consulta Técnica',
        'last_message_at' => now(),
    ]);

    Conversation::query()->create([
        'external_id' => '5492222222222@c.us',
        'title' => 'Otro Cliente',
        'last_message_at' => now()->subMinute(),
    ]);

    $this->get(route('whatsapp.conversations', ['chat_search' => 'Buscado']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/conversations')
            ->has('connection')
            ->where('connection.isReady', true)
            ->where('filters.chat_search', 'Buscado')
            ->has('conversations', 1)
            ->where('conversations.0.external_id', $matchingConversation->external_id),
        );
});

it('does not fetch a QR code when the OpenWA session is ready', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    config(['openwa.api_key' => 'testing-key']);

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('health')->once()->andReturn(['status' => 'ok']);
    $client->shouldReceive('findSessionByName')->once()->andReturn([
        'id' => 'session-1',
        'status' => 'ready',
    ]);
    $client->shouldReceive('qr')->never();

    $this->instance(OpenWaClient::class, $client);

    $this->get(route('whatsapp.conversations'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/conversations')
            ->where('connection.status', 'ready')
            ->where('connection.isReady', true)
            ->where('connection.qr', null),
        );
});

it('redirects conversations to connect when OpenWA is unavailable', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    config(['openwa.api_key' => 'testing-key']);

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('health')->once()->andThrow(new ConnectionException('Connection refused'));
    $client->shouldReceive('findSessionByName')->once()->andThrow(new ConnectionException('Connection refused'));

    $this->instance(OpenWaClient::class, $client);

    $this->get(route('whatsapp.conversations'))
        ->assertRedirect(route('whatsapp.connect'));

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('health')->once()->andThrow(new ConnectionException('Connection refused'));
    $client->shouldReceive('findSessionByName')->once()->andThrow(new ConnectionException('Connection refused'));

    $this->instance(OpenWaClient::class, $client);

    $this->get(route('whatsapp.connect'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/connect')
            ->where('openwa.error', 'No se pudo conectar con OpenWA. Revisá que el servicio esté levantado y que OPENWA_BASE_URL apunte a /api.'),
        );
});

it('filters messages for the selected conversation by message search', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    bindReadyOpenWaClient();

    $conversation = Conversation::query()->create([
        'external_id' => '5493333333333@c.us',
        'title' => 'Cliente Mensajes',
        'last_message_at' => now(),
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'body' => 'Necesito una factura de septiembre',
        'status' => 'received',
        'received_at' => now()->subMinute(),
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'body' => 'Solo saludo sin palabra clave',
        'status' => 'received',
        'received_at' => now(),
    ]);

    $this->get(route('whatsapp.conversations', [
        'chat' => $conversation->external_id,
        'message_search' => 'factura',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/conversations')
            ->where('filters.message_search', 'factura')
            ->where('selectedChatId', $conversation->external_id)
            ->has('messages', 1)
            ->where('messages.0.body', 'Necesito una factura de septiembre'),
        );
});

it('uses the newest message as conversation preview when cached last message is stale or missing', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    bindReadyOpenWaClient();

    $staleConversation = Conversation::query()->create([
        'external_id' => '5494444444444@c.us',
        'title' => 'Cache viejo',
        'last_message_at' => now()->subDays(2),
    ]);

    $oldMessage = Message::query()->create([
        'conversation_id' => $staleConversation->id,
        'direction' => 'inbound',
        'body' => 'Mensaje viejo cacheado',
        'status' => 'received',
        'received_at' => now()->subDays(2),
    ]);

    Message::query()->create([
        'conversation_id' => $staleConversation->id,
        'direction' => 'outbound',
        'body' => 'Mensaje más nuevo aunque el cache apunte al viejo',
        'status' => 'accepted',
        'sent_at' => now()->subHour(),
    ]);

    $staleConversation->forceFill([
        'last_message_id' => $oldMessage->id,
        'last_message_at' => $oldMessage->received_at,
    ])->save();

    $missingCacheConversation = Conversation::query()->create([
        'external_id' => '5495555555555@c.us',
        'title' => 'Cache nulo',
    ]);

    Message::query()->create([
        'conversation_id' => $missingCacheConversation->id,
        'direction' => 'inbound',
        'body' => 'Mensaje visible con cache nulo',
        'status' => 'received',
        'received_at' => now(),
    ]);

    $this->get(route('whatsapp.conversations'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/conversations')
            ->has('conversations', 2)
            ->where('conversations.0.external_id', $missingCacheConversation->external_id)
            ->where('conversations.0.last_message_preview', 'Mensaje visible con cache nulo')
            ->where('conversations.0.last_message_body', 'Mensaje visible con cache nulo')
            ->where('conversations.0.last_message_direction', 'inbound')
            ->where('conversations.1.external_id', $staleConversation->external_id)
            ->where('conversations.1.last_message_preview', 'Mensaje más nuevo aunque el cache apunte al viejo')
            ->where('conversations.1.last_message_body', 'Mensaje más nuevo aunque el cache apunte al viejo')
            ->where('conversations.1.last_message_direction', 'outbound'),
        );
});

it('uses media type fallback as conversation preview when media has no caption', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    bindReadyOpenWaClient();

    $conversation = Conversation::query()->create([
        'external_id' => '5496666666666@c.us',
        'title' => 'Cliente Multimedia',
        'last_message_at' => now(),
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'body' => null,
        'type' => 'audio',
        'status' => 'received',
        'received_at' => now(),
        'media_download_status' => 'pending',
    ]);

    $this->get(route('whatsapp.conversations'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/conversations')
            ->where('conversations.0.external_id', $conversation->external_id)
            ->where('conversations.0.last_message_preview', 'Audio')
            ->where('conversations.0.last_message_body', null),
        );
});

function bindReadyOpenWaClient(): void
{
    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('health')->once()->andReturn(['status' => 'ok']);
    $client->shouldReceive('findSessionByName')->once()->andReturn([
        'id' => 'session-1',
        'status' => 'ready',
        'engineLoaded' => true,
    ]);
    $client->shouldReceive('qr')->never();

    test()->instance(OpenWaClient::class, $client);
}
