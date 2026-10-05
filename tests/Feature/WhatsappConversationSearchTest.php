<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\OpenWaClient;
use App\Services\WhatsappMessageImporter;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
            ->where('openwa.status', 'error')
            ->where('openwa.isReady', false)
            ->where('openwa.canStart', true)
            ->where('openwa.error', 'No pudimos conectar. Reintentá en unos segundos.'),
        );
});

it('normalizes conversation display names without exposing raw chat identifiers', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    bindReadyOpenWaClient();

    $knownContact = Contact::query()->create([
        'external_id' => '5491166666666@c.us',
        'name' => 'Cliente Guardado',
        'push_name' => 'Alias Guardado',
        'phone' => '5491166666666',
    ]);

    $knownConversation = Conversation::query()->create([
        'external_id' => '5491166666666@lid',
        'contact_id' => $knownContact->id,
        'title' => '5491166666666@lid',
        'last_message_at' => now()->subMinutes(3),
    ]);

    $unknownPhoneConversation = Conversation::query()->create([
        'external_id' => '5491188888888@c.us',
        'title' => '5491188888888@c.us',
        'last_message_at' => now()->subMinutes(2),
    ]);

    $lidPhoneContact = Contact::query()->create([
        'external_id' => '5491177777777@c.us',
        'phone' => '5491177777777',
    ]);

    $lidPhoneConversation = Conversation::query()->create([
        'external_id' => '5491177777777@lid',
        'contact_id' => $lidPhoneContact->id,
        'title' => '5491177777777@lid',
        'last_message_at' => now()->subMinute(),
    ]);

    $unresolvedLidConversation = Conversation::query()->create([
        'external_id' => '123456789012345678901234@lid',
        'title' => '123456789012345678901234@lid',
        'last_message_at' => now(),
    ]);

    collect([
        [$knownConversation, now()->subMinutes(3)],
        [$unknownPhoneConversation, now()->subMinutes(2)],
        [$lidPhoneConversation, now()->subMinute()],
        [$unresolvedLidConversation, now()],
    ])->each(function (array $fixture): void {
        [$conversation, $receivedAt] = $fixture;

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Mensaje de prueba',
            'status' => 'received',
            'received_at' => $receivedAt,
        ]);
    });

    $this->get(route('whatsapp.conversations'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/conversations')
            ->where('conversations.0.external_id', $unresolvedLidConversation->external_id)
            ->where('conversations.0.title', 'Contacto desconocido')
            ->where('conversations.1.external_id', $lidPhoneConversation->external_id)
            ->where('conversations.1.title', '5491177777777')
            ->where('conversations.2.external_id', $unknownPhoneConversation->external_id)
            ->where('conversations.2.title', '5491188888888')
            ->where('conversations.3.external_id', $knownConversation->external_id)
            ->where('conversations.3.title', 'Cliente Guardado')
        );
});

it('ignores own profile placeholder when displaying conversation names', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    bindReadyOpenWaClient();

    $pollutedPhoneContact = Contact::query()->create([
        'external_id' => '5491155555555@c.us',
        'name' => 'Mi Num',
        'push_name' => 'mi num',
    ]);

    $pollutedPhoneConversation = Conversation::query()->create([
        'external_id' => '5491155555555@c.us',
        'contact_id' => $pollutedPhoneContact->id,
        'title' => 'Mi num',
        'last_message_at' => now()->subMinutes(2),
    ]);

    $pollutedLidContact = Contact::query()->create([
        'external_id' => '123456789012345678901234@lid',
        'name' => 'Mi Num',
    ]);

    $pollutedLidConversation = Conversation::query()->create([
        'external_id' => '123456789012345678901234@lid',
        'contact_id' => $pollutedLidContact->id,
        'title' => 'mi num',
        'last_message_at' => now()->subMinute(),
    ]);

    $realNameContact = Contact::query()->create([
        'external_id' => '5491166666666@c.us',
        'name' => 'mauro',
    ]);

    $realNameConversation = Conversation::query()->create([
        'external_id' => '5491166666666@c.us',
        'contact_id' => $realNameContact->id,
        'title' => 'Mi Num',
        'last_message_at' => now(),
    ]);

    collect([
        [$pollutedPhoneConversation, now()->subMinutes(2)],
        [$pollutedLidConversation, now()->subMinute()],
        [$realNameConversation, now()],
    ])->each(function (array $fixture): void {
        [$conversation, $receivedAt] = $fixture;

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Mensaje de prueba',
            'status' => 'received',
            'received_at' => $receivedAt,
        ]);
    });

    $this->get(route('whatsapp.conversations'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/conversations')
            ->where('conversations.0.external_id', $realNameConversation->external_id)
            ->where('conversations.0.title', 'mauro')
            ->where('conversations.1.external_id', $pollutedLidConversation->external_id)
            ->where('conversations.1.title', 'Contacto desconocido')
            ->where('conversations.2.external_id', $pollutedPhoneConversation->external_id)
            ->where('conversations.2.title', '5491155555555')
        );
});

it('does not save outbound message push names as remote contact names', function () {
    app(WhatsappMessageImporter::class)->importMessage([
        'id' => 'wamid-outbound-placeholder-1',
        'chatId' => '5491199999999@c.us',
        'to' => '5491199999999@c.us',
        'fromMe' => true,
        'body' => 'Respuesta',
        'notifyName' => 'Mi Num',
        'pushName' => 'Mi Num',
        'timestamp' => now()->timestamp,
    ]);

    $contact = Contact::query()->where('external_id', '5491199999999@c.us')->firstOrFail();
    $conversation = Conversation::query()->where('external_id', '5491199999999@c.us')->firstOrFail();

    expect($contact->name)->toBeNull()
        ->and($contact->push_name)->toBeNull()
        ->and($conversation->title)->toBe('5491199999999@c.us');
});

it('does not filter loaded messages by message search anymore', function () {
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
            ->where('filters.message_search', '')
            ->where('selectedChatId', $conversation->external_id)
            ->has('messages', 2)
            ->where('messages.0.body', 'Necesito una factura de septiembre')
            ->where('messages.1.body', 'Solo saludo sin palabra clave'),
        );
});

it('returns a fresh cached contact avatar without calling OpenWA', function () {
    $this->withoutMiddleware(Authenticate::class);

    $contact = Contact::query()->create([
        'external_id' => '5491111111111@c.us',
        'profile_photo_url' => 'https://cdn.example/avatar.jpg',
        'profile_photo_fetched_at' => now(),
    ]);

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldNotReceive('health');
    $client->shouldNotReceive('findSessionByName');
    $client->shouldNotReceive('contactProfilePicture');

    $this->instance(OpenWaClient::class, $client);

    $this->postJson(route('whatsapp.contacts.avatar', $contact))
        ->assertOk()
        ->assertJson([
            'avatar_url' => 'https://cdn.example/avatar.jpg',
        ]);
});

it('fetches and caches a stale contact avatar on demand', function () {
    $this->withoutMiddleware(Authenticate::class);

    $contact = Contact::query()->create([
        'external_id' => '5491111111111@c.us',
        'profile_photo_fetched_at' => now()->subDays(2),
    ]);

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('health')->once()->andReturn(['status' => 'ok']);
    $client->shouldReceive('findSessionByName')->once()->andReturn([
        'id' => 'session-1',
        'status' => 'ready',
    ]);
    $client->shouldReceive('contactProfilePicture')
        ->once()
        ->with('session-1', $contact->external_id)
        ->andReturn('https://cdn.example/fresh-avatar.jpg');

    $this->instance(OpenWaClient::class, $client);

    $this->postJson(route('whatsapp.contacts.avatar', $contact))
        ->assertOk()
        ->assertJson([
            'avatar_url' => 'https://cdn.example/fresh-avatar.jpg',
        ]);

    $contact->refresh();

    expect($contact->profile_photo_url)->toBe('https://cdn.example/fresh-avatar.jpg')
        ->and($contact->profile_photo_fetched_at)->not->toBeNull()
        ->and($contact->profile_photo_error)->toBeNull();
});

it('fetches and caches contact avatar from the OpenWA profile picture endpoint', function () {
    $this->withoutMiddleware(Authenticate::class);

    config()->set('openwa.base_url', 'http://openwa.test/api');
    config()->set('openwa.session_name', 'whatsapp-sistemas');

    Http::fake([
        'openwa.test/api/health' => Http::response(['status' => 'ok']),
        'openwa.test/api/sessions/session-1/contacts/5491111111111%40c.us/profile-picture' => Http::response([
            'url' => 'https://pps.example/avatar.jpg',
        ]),
        'openwa.test/api/sessions*' => Http::response([
            'data' => [
                'id' => 'session-1',
                'name' => 'whatsapp-sistemas',
                'status' => 'ready',
            ],
        ]),
    ]);

    $contact = Contact::query()->create([
        'external_id' => '5491111111111@c.us',
    ]);

    $this->postJson(route('whatsapp.contacts.avatar', $contact))
        ->assertOk()
        ->assertJson([
            'avatar_url' => 'https://pps.example/avatar.jpg',
        ]);

    $contact->refresh();

    expect($contact->profile_photo_url)->toBe('https://pps.example/avatar.jpg')
        ->and($contact->profile_photo_fetched_at)->not->toBeNull();
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

it('marks a selected conversation as read and exposes unread state', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    bindReadyOpenWaClient();

    $conversation = Conversation::query()->create([
        'external_id' => '5497777777777@c.us',
        'title' => 'Cliente No Leído',
        'last_message_at' => now(),
        'unread_count' => 3,
        'marked_unread_at' => now(),
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'body' => 'Mensaje pendiente de lectura',
        'status' => 'received',
        'received_at' => now(),
    ]);

    $this->get(route('whatsapp.conversations', ['chat' => $conversation->external_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/conversations')
            ->where('conversations.0.external_id', $conversation->external_id)
            ->where('conversations.0.unread_count', 0)
            ->where('conversations.0.marked_unread_at', null)
            ->where('firstUnreadMessageId', $message->id),
        );

    $conversation->refresh();

    expect($conversation->unread_count)->toBe(0)
        ->and($conversation->marked_unread_at)->toBeNull()
        ->and($conversation->last_read_at)->not->toBeNull();
});

it('marks a conversation unread manually without inventing a count', function () {
    $this->withoutMiddleware(Authenticate::class);

    $conversation = Conversation::query()->create([
        'external_id' => '5498888888888@c.us',
        'title' => 'Cliente Manual',
        'last_message_at' => now(),
        'unread_count' => 0,
    ]);

    $this->post(route('whatsapp.conversations.mark-unread', $conversation))
        ->assertRedirect(route('whatsapp.conversations', ['chat_search' => '']));

    $conversation->refresh();

    expect($conversation->unread_count)->toBe(0)
        ->and($conversation->marked_unread_at)->not->toBeNull();
});

it('hides conversations from the list by default', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    bindReadyOpenWaClient();

    $visibleConversation = Conversation::query()->create([
        'external_id' => '5491011111111@c.us',
        'title' => 'Cliente Visible',
        'last_message_at' => now(),
    ]);

    $hiddenConversation = Conversation::query()->create([
        'external_id' => '5491022222222@c.us',
        'title' => 'Cliente Oculto',
        'last_message_at' => now()->addMinute(),
        'hidden_at' => now(),
        'hidden_reason' => 'user_hidden',
    ]);

    Message::query()->create([
        'conversation_id' => $visibleConversation->id,
        'direction' => 'inbound',
        'body' => 'Visible',
        'status' => 'received',
        'received_at' => now(),
    ]);

    Message::query()->create([
        'conversation_id' => $hiddenConversation->id,
        'direction' => 'inbound',
        'body' => 'Oculto',
        'status' => 'received',
        'received_at' => now()->addMinute(),
    ]);

    $this->get(route('whatsapp.conversations'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/conversations')
            ->has('conversations', 1)
            ->where('conversations.0.external_id', $visibleConversation->external_id),
        );
});

it('hides a conversation locally and redirects without selecting it', function () {
    $this->withoutMiddleware(Authenticate::class);

    $conversation = Conversation::query()->create([
        'external_id' => '5491033333333@c.us',
        'title' => 'Cliente Local',
        'last_message_at' => now(),
    ]);

    $this->post(route('whatsapp.conversations.hide', $conversation), [
        'chat' => $conversation->external_id,
        'chat_search' => 'Local',
    ])->assertRedirect(route('whatsapp.conversations', ['chat_search' => 'Local']));

    $conversation->refresh();

    expect($conversation->hidden_at)->not->toBeNull()
        ->and($conversation->hidden_reason)->toBe('user_hidden');
});

it('unhides a hidden conversation when a new webhook message is imported', function () {
    $conversation = Conversation::query()->create([
        'external_id' => '5491044444444@c.us',
        'title' => 'Cliente Oculto',
        'last_message_at' => now()->subDay(),
        'hidden_at' => now()->subHour(),
        'hidden_reason' => 'user_hidden',
    ]);

    app(WhatsappMessageImporter::class)->importMessage([
        'id' => 'wamid-unhide-1',
        'chatId' => $conversation->external_id,
        'fromMe' => false,
        'body' => 'Volví con una consulta',
        'timestamp' => now()->timestamp,
    ]);

    $conversation->refresh();

    expect($conversation->hidden_at)->toBeNull()
        ->and($conversation->hidden_reason)->toBeNull()
        ->and($conversation->unread_count)->toBe(1);
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
