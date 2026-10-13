<?php

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
    ]);
});

it('upserts contacts from the OpenWA contacts endpoint', function () {
    Contact::query()->create([
        'external_id' => '5492222222222@c.us',
        'name' => 'Nombre anterior',
        'push_name' => 'Anterior',
        'phone' => '000',
    ]);

    Http::fake(function ($request) {
        $url = rawurldecode($request->url());

        if (str_contains($url, '/sessions/session-1/contacts')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            if (($query['offset'] ?? null) === '2') {
                return Http::response(['data' => []]);
            }

            return Http::response([
                'data' => [
                    [
                        'id' => '5491111111111@c.us',
                        'name' => 'Cliente Uno',
                        'pushName' => 'Cliente',
                        'number' => '5491111111111',
                        'profilePicUrl' => 'https://cdn.test/cliente-uno.jpg',
                    ],
                    [
                        'contactId' => '5492222222222@c.us',
                        'shortName' => 'Cliente Dos',
                        'notifyName' => 'Dos',
                        'user' => '5492222222222',
                        'picture' => 'https://cdn.test/cliente-dos.jpg',
                    ],
                ],
            ]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('whatsapp:sync-contacts', ['--limit' => 2])
        ->expectsOutput('Contactos procesados: 2')
        ->expectsOutput('Contactos creados: 1')
        ->expectsOutput('Contactos actualizados: 1')
        ->expectsOutput('Contactos omitidos: 0')
        ->assertSuccessful();

    expect(Contact::query()->where('external_id', '5491111111111@c.us')->first())
        ->name->toBe('Cliente Uno')
        ->push_name->toBe('Cliente')
        ->phone->toBe('5491111111111')
        ->profile_photo_url->toBe('https://cdn.test/cliente-uno.jpg')
        ->is_my_contact->toBeTrue()
        ->source->toBe('openwa-addressbook')
        ->last_synced_at->not->toBeNull()
        ->and(Contact::query()->where('external_id', '5492222222222@c.us')->first())
        ->name->toBe('Cliente Dos')
        ->push_name->toBe('Dos')
        ->phone->toBe('5492222222222')
        ->profile_photo_url->toBe('https://cdn.test/cliente-dos.jpg')
        ->is_my_contact->toBeTrue()
        ->source->toBe('openwa-addressbook')
        ->last_synced_at->not->toBeNull();
});

it('preserves existing non-empty contact fields when OpenWA sends empty values', function () {
    Contact::query()->create([
        'external_id' => '5493333333333@c.us',
        'name' => 'Nombre Local',
        'push_name' => 'Push Local',
        'phone' => '5493333333333',
        'profile_photo_url' => 'https://cdn.test/local.jpg',
    ]);

    Http::fake(function ($request) {
        $url = rawurldecode($request->url());

        if (str_contains($url, '/sessions/session-1/contacts')) {
            return Http::response([
                'data' => [[
                    'id' => '5493333333333@c.us',
                    'isMyContact' => true,
                    'name' => '',
                    'pushName' => null,
                    'phone' => '',
                    'profilePicUrl' => null,
                ]],
            ]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $this->artisan('whatsapp:sync-contacts')
        ->expectsOutput('Contactos procesados: 1')
        ->expectsOutput('Contactos creados: 0')
        ->expectsOutput('Contactos actualizados: 1')
        ->expectsOutput('Contactos omitidos: 0')
        ->assertSuccessful();

    expect(Contact::query()->where('external_id', '5493333333333@c.us')->first())
        ->name->toBe('Nombre Local')
        ->push_name->toBe('Push Local')
        ->phone->toBe('5493333333333')
        ->profile_photo_url->toBe('https://cdn.test/local.jpg');
});

it('skips contacts when OpenWA explicitly says they are not address book contacts', function () {
    fakeOpenWaContacts([[
        'id' => '5494444444444@c.us',
        'name' => 'No Agenda',
        'isMyContact' => false,
    ]]);

    $this->artisan('whatsapp:sync-contacts')
        ->expectsOutput('Contactos procesados: 0')
        ->expectsOutput('Contactos creados: 0')
        ->expectsOutput('Contactos actualizados: 0')
        ->expectsOutput('Contactos omitidos: 1')
        ->assertSuccessful();

    expect(Contact::query()->where('external_id', '5494444444444@c.us')->exists())->toBeFalse();
});

it('keeps contacts when OpenWA explicitly says they are address book contacts', function () {
    fakeOpenWaContacts([[
        'id' => '5495555555555@c.us',
        'name' => 'Agenda Real',
        'isMyContact' => true,
    ]]);

    $this->artisan('whatsapp:sync-contacts')
        ->expectsOutput('Contactos procesados: 1')
        ->expectsOutput('Contactos creados: 1')
        ->expectsOutput('Contactos actualizados: 0')
        ->expectsOutput('Contactos omitidos: 0')
        ->assertSuccessful();

    expect(Contact::query()->where('external_id', '5495555555555@c.us')->first())
        ->is_my_contact->toBeTrue()
        ->source->toBe('openwa-addressbook');
});

it('uses conservative fallback for named individual contacts and rejects noisy contacts', function () {
    fakeOpenWaContacts([
        [
            'id' => '5496666666666@c.us',
            'formattedName' => 'Cliente Guardado',
        ],
        [
            'id' => '5497777777777@c.us',
            'name' => '!!! 😄',
        ],
        [
            'id' => '120363000000000000@g.us',
            'name' => 'Grupo Guardado',
        ],
    ]);

    $this->artisan('whatsapp:sync-contacts')
        ->expectsOutput('Contactos procesados: 1')
        ->expectsOutput('Contactos creados: 1')
        ->expectsOutput('Contactos actualizados: 0')
        ->expectsOutput('Contactos omitidos: 2')
        ->assertSuccessful();

    expect(Contact::query()->where('external_id', '5496666666666@c.us')->exists())->toBeTrue()
        ->and(Contact::query()->where('external_id', '5497777777777@c.us')->exists())->toBeFalse()
        ->and(Contact::query()->where('external_id', '120363000000000000@g.us')->exists())->toBeFalse();
});

it('filters agenda contacts by default and allows include all query', function () {
    $user = new User([
        'name' => 'Tester',
        'email' => 'tester@example.test',
    ]);
    $user->setAttribute('id', 1);

    Contact::query()->create([
        'external_id' => '5491111111111@c.us',
        'name' => 'Agenda Real',
        'is_my_contact' => true,
        'source' => 'openwa-addressbook',
    ]);
    Contact::query()->create([
        'external_id' => '5492222222222@c.us',
        'name' => 'Descubierto',
        'is_my_contact' => false,
        'source' => 'openwa-discovered',
    ]);

    $this->actingAs($user)
        ->getJson(route('whatsapp.contacts.index'))
        ->assertOk()
        ->assertJsonCount(1, 'contacts')
        ->assertJsonPath('contacts.0.display_name', 'Agenda Real');

    $this->actingAs($user)
        ->getJson(route('whatsapp.contacts.index', ['include' => 'all']))
        ->assertOk()
        ->assertJsonCount(2, 'contacts');
});

/**
 * @param  list<array<string, mixed>>  $contacts
 */
function fakeOpenWaContacts(array $contacts): void
{
    Http::fake(function ($request) use ($contacts) {
        $url = rawurldecode($request->url());

        if (str_contains($url, '/sessions/session-1/contacts')) {
            return Http::response(['data' => $contacts]);
        }

        if (str_contains($url, '/sessions')) {
            return Http::response([
                'data' => [
                    'id' => 'session-1',
                    'name' => 'whatsapp-sistemas',
                    'status' => 'ready',
                ],
            ]);
        }

        return Http::response([], 404);
    });
}
