<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Support\Facades\Schema;

it('reports counts without deleting when running a dry run', function () {
    seedLocalWhatsappData();

    $this->artisan('whatsapp:reset-local-data --dry-run')
        ->expectsOutput('Local WhatsApp data counts:')
        ->expectsOutput('messages: 1')
        ->expectsOutput('conversations: 1')
        ->expectsOutput('contacts: 1')
        ->expectsOutput('whatsapp_accounts: 1')
        ->expectsOutput('Dry run only. No data was deleted.')
        ->assertSuccessful();

    expect(Message::query()->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1)
        ->and(WhatsappAccount::query()->count())->toBe(1);
});

it('fails without the exact confirmation and does not delete data', function () {
    seedLocalWhatsappData();

    $this->artisan('whatsapp:reset-local-data')
        ->expectsOutput('Confirmation required. This command deletes local WhatsApp data only.')
        ->expectsOutput('Run: php artisan whatsapp:reset-local-data --confirm=RESET_WHATSAPP_LOCAL_DATA')
        ->expectsOutput('Preview first: php artisan whatsapp:reset-local-data --dry-run')
        ->assertFailed();

    $this->artisan('whatsapp:reset-local-data --confirm=wrong')
        ->expectsOutput('Confirmation required. This command deletes local WhatsApp data only.')
        ->expectsOutput('Run: php artisan whatsapp:reset-local-data --confirm=RESET_WHATSAPP_LOCAL_DATA')
        ->expectsOutput('Preview first: php artisan whatsapp:reset-local-data --dry-run')
        ->assertFailed();

    expect(Message::query()->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1)
        ->and(WhatsappAccount::query()->count())->toBe(1);
});

it('deletes local WhatsApp data with the exact confirmation and preserves users', function () {
    $user = Schema::hasTable('users') ? User::factory()->create() : null;

    seedLocalWhatsappData();

    $this->artisan('whatsapp:reset-local-data --confirm=RESET_WHATSAPP_LOCAL_DATA')
        ->expectsOutput('Local WhatsApp data counts:')
        ->expectsOutput('messages: 1')
        ->expectsOutput('conversations: 1')
        ->expectsOutput('contacts: 1')
        ->expectsOutput('whatsapp_accounts: 1')
        ->expectsOutput('Deleted local WhatsApp data successfully.')
        ->assertSuccessful();

    expect(Message::query()->count())->toBe(0)
        ->and(Conversation::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0)
        ->and(WhatsappAccount::query()->count())->toBe(0);

    if ($user !== null) {
        expect(User::query()->whereKey($user->getKey())->exists())->toBeTrue();
    }
});

function seedLocalWhatsappData(): void
{
    WhatsappAccount::query()->create([
        'name' => 'whatsapp-sistemas',
        'status' => 'connected',
    ]);

    $contact = Contact::query()->create([
        'external_id' => '5491111111111@c.us',
        'name' => 'Cliente Local',
    ]);

    $conversation = Conversation::query()->create([
        'contact_id' => $contact->id,
        'external_id' => '5491111111111@c.us',
        'title' => 'Cliente Local',
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'external_id' => 'wamid-local-reset',
        'direction' => 'inbound',
        'body' => 'Mensaje local',
        'status' => 'received',
        'received_at' => now(),
    ]);

    $conversation->forceFill([
        'last_message_id' => $message->id,
        'last_message_at' => $message->received_at,
    ])->save();
}
