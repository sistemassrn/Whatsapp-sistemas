<?php

use App\Services\OpenWaClient;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['openwa.api_key' => 'testing-key']);
    Cache::flush();
});

it('redirects connect to conversations when the WhatsApp session is ready', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('health')->once()->andReturn(['status' => 'ok']);
    $client->shouldReceive('findSessionByName')->once()->andReturn([
        'id' => 'session-1',
        'status' => 'ready',
        'engineLoaded' => true,
    ]);
    $client->shouldReceive('qr')->never();

    $this->instance(OpenWaClient::class, $client);

    $this->get(route('whatsapp.connect'))
        ->assertRedirect(route('whatsapp.conversations'));
});

it('redirects conversations to connect when QR/manual pairing is needed', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('health')->once()->andReturn(['status' => 'ok']);
    $client->shouldReceive('findSessionByName')->once()->andReturn([
        'id' => 'session-1',
        'status' => 'qr_ready',
        'engineLoaded' => true,
    ]);
    $client->shouldReceive('qr')->never();

    $this->instance(OpenWaClient::class, $client);

    $this->get(route('whatsapp.conversations'))
        ->assertRedirect(route('whatsapp.connect'));
});

it('does not check OpenWA connection during conversation partial reloads that omit connection', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();
    config(['app.asset_url' => 'http://testing']);

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('health')->never();
    $client->shouldReceive('findSessionByName')->never();
    $client->shouldReceive('qr')->never();

    $this->instance(OpenWaClient::class, $client);

    $this
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash('xxh128', 'http://testing'),
            'X-Inertia-Partial-Component' => 'whatsapp/conversations',
            'X-Inertia-Partial-Data' => 'conversations,messages,messageLimit,hasMoreMessages,firstUnreadMessageId,emptyState,filters,flash,selectedChatId',
        ])
        ->get(route('whatsapp.conversations'))
        ->assertOk();
});

it('auto-starts a missing session on the connect page and shows QR status', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('health')->once()->andReturn(['status' => 'ok']);
    $client->shouldReceive('findSessionByName')->once()->andReturn(null);
    $client->shouldReceive('createSession')->once()->andReturn([
        'id' => 'session-1',
        'name' => 'whatsapp-sistemas',
        'status' => 'created',
        'engineLoaded' => false,
        'token' => 'secret-token',
    ]);
    $client->shouldReceive('startSession')->once()->with('session-1')->andReturn([
        'id' => 'session-1',
        'status' => 'initializing',
    ]);
    $client->shouldReceive('findSessionByName')->once()->andReturn([
        'id' => 'session-1',
        'name' => 'whatsapp-sistemas',
        'status' => 'qr_ready',
        'engineLoaded' => true,
        'token' => 'secret-token',
    ]);
    $client->shouldReceive('qr')->once()->with('session-1')->andReturn([
        'qrCode' => 'data:image/png;base64,testing',
        'status' => 'qr_ready',
    ]);

    $this->instance(OpenWaClient::class, $client);

    $this->get(route('whatsapp.connect'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('whatsapp/connect')
            ->where('openwa.started', true)
            ->where('openwa.autoStarted', true)
            ->where('openwa.status', 'qr_ready')
            ->where('openwa.isReady', false)
            ->where('openwa.isStarted', true)
            ->where('openwa.session.name', 'whatsapp-sistemas')
            ->missing('openwa.session.token')
            ->has('openwa.lastCheckedAt')
            ->where('openwa.qrCode.status', 'qr_ready'),
        );
});

it('reuses cached QR status on repeated connect page checks', function () {
    $this->withoutMiddleware(Authenticate::class);
    $this->withoutVite();

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('health')->twice()->andReturn(['status' => 'ok']);
    $client->shouldReceive('findSessionByName')->twice()->andReturn([
        'id' => 'session-1',
        'name' => 'whatsapp-sistemas',
        'status' => 'qr_ready',
        'engineLoaded' => true,
    ]);
    $client->shouldReceive('qr')->once()->with('session-1')->andReturn([
        'qrCode' => 'data:image/png;base64,testing',
        'status' => 'qr_ready',
    ]);

    $this->instance(OpenWaClient::class, $client);

    $this->get(route('whatsapp.connect'))->assertOk();
    $this->get(route('whatsapp.connect'))->assertOk();
});

it('closes the WhatsApp session through OpenWA logout', function () {
    $this->withoutMiddleware(Authenticate::class);

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('findSessionByName')->once()->andReturn([
        'id' => 'session-1',
        'status' => 'ready',
    ]);
    $client->shouldReceive('logoutSession')->once()->with('session-1')->andReturn([
        'id' => 'session-1',
        'status' => 'disconnected',
    ]);

    $this->instance(OpenWaClient::class, $client);

    $this->post(route('whatsapp.disconnect'))
        ->assertRedirect(route('whatsapp.connect'))
        ->assertSessionHas('success');
});
