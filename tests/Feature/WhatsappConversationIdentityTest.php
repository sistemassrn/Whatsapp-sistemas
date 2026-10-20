<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\OpenWaClient;
use App\Services\WhatsappConversationResolver;
use App\Services\WhatsappMessageImporter;
use App\Services\WhatsappMessageImportSource;
use App\Services\WhatsappMessageMediaDownloader;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutMiddleware(Authenticate::class);
    Http::preventStrayRequests();
});

it('opens the existing conversation for differently formatted known phones and different ids', function () {
    $existingContact = Contact::query()->create(['external_id' => 'provider-old', 'phone' => '+54 (911) 4444-4444']);
    $agendaContact = Contact::query()->create(['external_id' => 'provider-new', 'phone' => '5491144444444']);
    $conversation = Conversation::query()->create(['external_id' => 'chat-old', 'contact_id' => $existingContact->id, 'title' => 'Preserved', 'unread_count' => 3]);
    $message = Message::query()->create(['conversation_id' => $conversation->id, 'external_id' => 'existing-media', 'direction' => 'inbound', 'type' => 'image', 'status' => 'received', 'media_path' => 'inbound/original.jpg', 'media_metadata' => ['caption' => 'Keep']]);
    $conversation->update(['last_message_id' => $message->id, 'last_message_at' => $message->created_at]);
    $attributes = $conversation->fresh()->getAttributes();
    $messageAttributes = $message->fresh()->getAttributes();

    $this->post(route('whatsapp.contacts.open-conversation', $agendaContact))
        ->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));

    expect(Conversation::query()->count())->toBe(1)
        ->and($conversation->fresh()->getAttributes())->toBe($attributes)
        ->and($message->fresh()->getAttributes())->toBe($messageAttributes);
});

it('prefers a visible phone alias over an exact hidden duplicate without deleting or modifying the duplicate', function () {
    $contact = Contact::query()->create(['external_id' => '5491144444444@c.us']);
    $hidden = Conversation::query()->create(['external_id' => $contact->external_id, 'hidden_at' => now(), 'hidden_reason' => 'user_hidden', 'unread_count' => 4]);
    $visible = Conversation::query()->create(['external_id' => '5491144444444@s.whatsapp.net', 'title' => 'Visible']);
    $hiddenAttributes = $hidden->fresh()->getAttributes();

    $this->post(route('whatsapp.contacts.open-conversation', $contact))
        ->assertRedirect(route('whatsapp.conversations', ['chat' => $visible->external_id]));

    expect(Conversation::query()->count())->toBe(2)
        ->and($hidden->fresh()->getAttributes())->toBe($hiddenAttributes);
});

it('reuses and unhides a hidden phone alias rather than creating an empty conversation', function () {
    $contact = Contact::query()->create(['external_id' => '5491144444444@c.us']);
    $hidden = Conversation::query()->create(['external_id' => '5491144444444@s.whatsapp.net', 'hidden_at' => now(), 'hidden_reason' => 'user_hidden', 'unread_count' => 2]);

    $this->post(route('whatsapp.contacts.open-conversation', $contact))
        ->assertRedirect(route('whatsapp.conversations', ['chat' => $hidden->external_id]));

    expect(Conversation::query()->count())->toBe(1)
        ->and($hidden->fresh())->hidden_at->toBeNull()->hidden_reason->toBeNull()->unread_count->toBe(2);
});

it('does not equate unrelated contacts by name', function () {
    $contact = Contact::query()->create(['external_id' => '5491144444444@c.us', 'name' => 'Same Name']);
    Conversation::query()->create(['external_id' => '5491155555555@c.us', 'title' => 'Same Name']);

    $this->post(route('whatsapp.contacts.open-conversation', $contact))
        ->assertRedirect(route('whatsapp.conversations', ['chat' => $contact->external_id]));

    $created = Conversation::query()->where('external_id', $contact->external_id)->firstOrFail();
    expect(Conversation::query()->count())->toBe(2)
        ->and($created->messages()->count())->toBe(0)
        ->and($created->last_message_id)->toBeNull();
});

it('never treats unresolved LID digits or unverified legacy LID phone fields as phone identity', function (string $phone) {
    Http::fake(fn () => Http::response([], 503));
    $contact = Contact::query()->create(['external_id' => '5491144444444@lid', 'phone' => $phone]);
    Conversation::query()->create(['external_id' => '5491144444444@c.us']);
    Conversation::query()->create(['external_id' => '5491155555555@c.us']);

    $this->post(route('whatsapp.contacts.open-conversation', $contact))
        ->assertRedirect(route('whatsapp.conversations', ['chat' => $contact->external_id]));

    expect(Conversation::query()->count())->toBe(3);
})->with(['5491144444444', '5491155555555']);

it('reuses a LID conversation only with a persisted verified phone mapping', function () {
    $contact = Contact::query()->create(['external_id' => '5491144444444@c.us']);
    Contact::query()->create(['external_id' => '123456789@lid'])->forceFill(['verified_phone' => '5491144444444'])->save();
    $conversation = Conversation::query()->create(['external_id' => '123456789@lid', 'contact_id' => $contact->id]);

    $this->post(route('whatsapp.contacts.open-conversation', $contact))
        ->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));

    expect(Conversation::query()->count())->toBe(1);
});

it('imports phone alias messages into the visible conversation without moving the last pointer backwards', function () {
    $contact = Contact::query()->create(['external_id' => '5491144444444@c.us', 'phone' => '5491144444444']);
    $conversation = Conversation::query()->create(['external_id' => $contact->external_id, 'contact_id' => $contact->id, 'unread_count' => 2]);
    $latest = Message::query()->create(['conversation_id' => $conversation->id, 'external_id' => 'latest', 'body' => 'Latest', 'direction' => 'inbound', 'status' => 'received', 'received_at' => now()]);
    $conversation->update(['last_message_id' => $latest->id, 'last_message_at' => $latest->received_at]);

    $payload = ['id' => 'alias-message', 'chatId' => '5491144444444@s.whatsapp.net', 'body' => 'Older', 'timestamp' => now()->subHour()->timestamp];
    $importer = app(WhatsappMessageImporter::class);
    $result = $importer->importMessage($payload);
    $importer->importMessage($payload);

    expect($result['message']->conversation_id)->toBe($conversation->id)
        ->and(Conversation::query()->count())->toBe(1)
        ->and(Message::query()->count())->toBe(2)
        ->and($conversation->fresh())->last_message_id->toBe($latest->id)->unread_count->toBe(3)
        ->and($contact->fresh()->phone)->toBe('5491144444444');
});

it('does not associate an outbound recipient with the local sender identity', function () {
    $own = Contact::query()->create(['external_id' => '5491100000000@c.us']);
    Conversation::query()->create(['external_id' => $own->external_id, 'contact_id' => $own->id]);
    $result = app(WhatsappMessageImporter::class)->importMessage([
        'id' => 'outbound-alias', 'chatId' => '5491144444444@c.us', 'to' => '5491144444444@c.us',
        'fromMe' => true, 'sender' => ['id' => $own->external_id, 'phone' => '5491100000000'], 'body' => 'Hello',
    ]);

    expect($result['message']->conversation->contact->external_id)->toBe('5491144444444@c.us')
        ->and(Conversation::query()->count())->toBe(2);
});

it('keeps group identity separate from a participants phone', function () {
    $contact = Contact::query()->create(['external_id' => '5491144444444@c.us']);
    Conversation::query()->create(['external_id' => '123456789@g.us', 'contact_id' => $contact->id]);
    $result = app(WhatsappConversationResolver::class)->resolve($contact->external_id, $contact);

    expect($result->external_id)->toBe($contact->external_id)
        ->and(Conversation::query()->count())->toBe(2);
});

it('does not recreate a phone alias conversation through history sync', function (string $command) {
    config(['openwa.api_key' => 'testing-key', 'openwa.base_url' => 'http://openwa.test/api', 'openwa.session_name' => 'whatsapp-sistemas']);
    $contact = Contact::query()->create(['external_id' => '5491144444444@s.whatsapp.net', 'push_name' => 'Keep push name', 'phone' => '5491144444444']);
    $conversation = Conversation::query()->create(['external_id' => '5491144444444@c.us', 'title' => 'Keep title']);
    Http::fake(function ($request) {
        $url = rawurldecode($request->url());
        if (str_contains($url, '/history')) {
            return Http::response(['data' => [['id' => 'sync-alias-message', 'chatId' => '5491144444444@s.whatsapp.net', 'body' => 'Hello', 'timestamp' => now()->timestamp]]]);
        }
        if (str_contains($url, '/chats')) {
            return Http::response(['data' => [['id' => '5491144444444@s.whatsapp.net', 'name' => 'Remote name', 'timestamp' => now()->timestamp]]]);
        }

        return Http::response(['data' => ['id' => 'session-1', 'name' => 'whatsapp-sistemas', 'status' => 'ready']]);
    });

    $this->artisan($command)->assertSuccessful();
    $this->artisan($command)->assertSuccessful();

    expect(Conversation::query()->count())->toBe(1)
        ->and(Message::query()->sole()->conversation_id)->toBe($conversation->id)
        ->and($conversation->fresh()->title)->toBe('Keep title')
        ->and($contact->fresh())->push_name->toBe('Keep push name')->phone->toBe('5491144444444');
})->with(['whatsapp:sync-initial', 'whatsapp:sync-recent']);

it('rejects non phone identifiers during normalization', function (string $value) {
    expect(app(WhatsappConversationResolver::class)->normalizePhone($value))->toBeNull();
})->with(['123456789@lid', '123456789@g.us', 'Client 123456789', '123', '1234567890123456']);

it('verifies a LID through OpenWA and retains its mapping for subsequent incoming messages', function () {
    config(['openwa.base_url' => 'http://openwa.test/api', 'openwa.session_name' => 'whatsapp-sistemas']);
    $contact = Contact::query()->create(['external_id' => '123456789@lid', 'phone' => '123456789']);
    $conversation = Conversation::query()->create(['external_id' => '5491144444444@c.us']);
    Http::fake([
        '*contacts/123456789%40lid/phone' => Http::response(['contactId' => $contact->external_id, 'phone' => '5491144444444']),
        '*sessions*' => Http::response(['data' => ['id' => 'session-1', 'name' => 'whatsapp-sistemas', 'status' => 'ready']]),
    ]);

    $this->post(route('whatsapp.contacts.open-conversation', $contact))
        ->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]));
    $result = app(WhatsappMessageImporter::class)->importMessage(['id' => 'lid-message', 'chatId' => $contact->external_id, 'body' => 'Hello']);

    expect($contact->fresh()->verified_phone)->toBe('5491144444444')
        ->and($result['message']->conversation_id)->toBe($conversation->id)
        ->and($result['message']->remoteChatId())->toBe($contact->external_id)
        ->and(Conversation::query()->count())->toBe(1);
    Http::assertSentCount(2);
});

it('does not trust a legacy LID contact association without verification', function () {
    $contact = Contact::query()->create(['external_id' => '5491144444444@c.us']);
    Conversation::query()->create(['external_id' => '123456789@lid', 'contact_id' => $contact->id]);

    $this->post(route('whatsapp.contacts.open-conversation', $contact))
        ->assertRedirect(route('whatsapp.conversations', ['chat' => $contact->external_id]));

    expect(Conversation::query()->count())->toBe(2);
});

it('rejects null or mismatched phone resolution responses', function (array $payload) {
    config(['openwa.base_url' => 'http://openwa.test/api']);
    Http::fake(fn () => Http::response($payload));
    $contact = Contact::query()->create(['external_id' => '123456789@lid']);

    app(WhatsappConversationResolver::class)->verifyLid($contact->external_id, 'session-1');

    expect($contact->fresh()->verified_phone)->toBeNull();
})->with([
    [['contactId' => '123456789@lid', 'phone' => null]],
    [['contactId' => 'other@lid', 'phone' => '5491144444444']],
    [['contactId' => '123456789@lid', 'phone' => '123456789@lid']],
]);

it('downloads alias media using the original remote chat id', function () {
    Storage::fake('whatsapp_media');
    $conversation = Conversation::query()->create(['external_id' => '5491144444444@c.us']);
    $result = app(WhatsappMessageImporter::class)->importMessage([
        'id' => 'alias-media', 'chatId' => '5491144444444@s.whatsapp.net', 'type' => 'image', 'mediaDataMissing' => true,
    ]);
    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('downloadMessageMedia')->once()->with('session-1', '5491144444444@s.whatsapp.net', 'alias-media')
        ->andReturn(['binary' => 'image-bytes', 'mimeType' => 'image/jpeg']);
    $downloader = new WhatsappMessageMediaDownloader($client);

    expect($downloader->attempt($result['message'], 'session-1'))->toBeTrue()
        ->and($result['message']->conversation_id)->toBe($conversation->id)
        ->and($result['message']->fresh()->media_metadata['source_chat_id'])->toBe('5491144444444@s.whatsapp.net');
});

it('keeps remote edit and delete addressing on the source alias', function () {
    $conversation = Conversation::query()->create(['external_id' => '5491144444444@c.us']);
    $message = app(WhatsappMessageImporter::class)->importMessage([
        'id' => 'alias-outbound', 'chatId' => '5491144444444@s.whatsapp.net', 'fromMe' => true, 'body' => 'Original',
    ])['message'];
    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('findSessionByName')->twice()->andReturn(['id' => 'session-1', 'status' => 'ready']);
    $client->shouldReceive('editMessage')->once()->with('session-1', '5491144444444@s.whatsapp.net', 'alias-outbound', 'Edited')->andReturn([]);
    $client->shouldReceive('deleteMessage')->once()->with('session-1', '5491144444444@s.whatsapp.net', 'alias-outbound')->andReturn([]);
    $this->app->instance(OpenWaClient::class, $client);

    $this->patch(route('whatsapp.messages.update', $message), ['body' => 'Edited'])
        ->assertRedirect(route('whatsapp.conversations', ['chat' => $conversation->external_id]))->assertSessionHas('success');
    $this->delete(route('whatsapp.messages.destroy', $message))->assertSessionHas('success');
});

it('retains a known phone when an alias payload omits phone metadata', function () {
    $existing = Contact::query()->create(['external_id' => 'provider-old', 'phone' => '+54 911 4444-4444']);
    $alias = Contact::query()->create(['external_id' => 'provider-new', 'phone' => '5491144444444']);
    $conversation = Conversation::query()->create(['external_id' => 'provider-old', 'contact_id' => $existing->id, 'hidden_at' => now(), 'hidden_reason' => 'user_hidden']);
    $result = app(WhatsappMessageImporter::class)->importMessage(['id' => 'opaque-alias', 'chatId' => $alias->external_id, 'body' => 'Hello'], source: WhatsappMessageImportSource::HistoricalSync);

    expect($result['message']->conversation_id)->toBe($conversation->id)
        ->and($alias->fresh()->phone)->toBe('5491144444444')
        ->and($conversation->fresh()->hidden_at)->not->toBeNull()
        ->and(Conversation::query()->count())->toBe(1);
});

it('fails closed when the verified phone endpoint is unavailable', function () {
    config(['openwa.base_url' => 'http://openwa.test/api']);
    Http::fake(['*' => Http::failedConnection()]);
    $contact = Contact::query()->create(['external_id' => '123456789@lid']);

    app(WhatsappConversationResolver::class)->verifyLid($contact->external_id, 'session-1');

    expect($contact->fresh()->verified_phone)->toBeNull();
});

it('compiles selection locking using SQL Server supported lock hints without connecting', function () {
    $connection = new SqlServerConnection(fn () => throw new RuntimeException('No database access allowed'), 'testing');
    $sql = $connection->table('conversations')->orderBy('id')->lockForUpdate()->toSql();

    expect($sql)->toContain('with(rowlock,updlock,holdlock)');
});

it('reuses hidden aliases when syncing saved chats without messages', function (string $command) {
    config(['openwa.base_url' => 'http://openwa.test/api', 'openwa.session_name' => 'whatsapp-sistemas']);
    $conversation = Conversation::query()->create(['external_id' => '5491144444444@c.us', 'hidden_at' => now(), 'hidden_reason' => 'user_hidden']);
    $contact = Contact::query()->create(['external_id' => '5491144444444@s.whatsapp.net', 'name' => 'Keep name', 'push_name' => 'Keep push', 'phone' => '5491144444444']);
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/history')) {
            return Http::response(['data' => []]);
        }
        if (str_contains($request->url(), '/chats')) {
            return Http::response(['data' => [['id' => '5491144444444@s.whatsapp.net', 'isMyContact' => true, 'timestamp' => now()->timestamp]]]);
        }

        return Http::response(['data' => ['id' => 'session-1', 'name' => 'whatsapp-sistemas', 'status' => 'ready']]);
    });

    $this->artisan($command)->assertSuccessful();

    expect(Conversation::query()->count())->toBe(1)
        ->and($conversation->fresh()->hidden_at)->not->toBeNull()
        ->and($contact->fresh())->name->toBe('Keep name')->push_name->toBe('Keep push')->phone->toBe('5491144444444');
})->with(['whatsapp:sync-initial', 'whatsapp:sync-recent']);
