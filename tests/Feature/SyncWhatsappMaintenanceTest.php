<?php

use App\Models\Message;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'openwa.api_key' => 'testing-key',
        'openwa.base_url' => 'http://openwa.test/api',
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.sync_recent.limit_chats' => 3,
        'openwa.sync_recent.limit_messages' => 4,
        'openwa.retry_media.limit' => 5,
        'openwa.retry_media.minutes' => 1440,
    ]);
});

it('runs recent sync before retrying media', function () {
    Http::fake(function ($request) {
        $url = rawurldecode(rawurldecode($request->url()));

        if (str_contains($url, '/sessions/session-1/chats')) {
            return Http::response(['data' => []]);
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

    $this->artisan('whatsapp:sync-maintenance')
        ->expectsOutput('Iniciando mantenimiento de sincronización de WhatsApp.')
        ->expectsOutput('Chats escaneados: 0')
        ->expectsOutput('Sincronización reciente completada. Reintentando medios pendientes.')
        ->expectsOutput('Intentados: 0')
        ->expectsOutput('Mantenimiento de sincronización de WhatsApp completado.')
        ->assertSuccessful();

    expect(Message::query()->count())->toBe(0);
});
