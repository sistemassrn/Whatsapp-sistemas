<?php

use App\Jobs\RunWhatsappSyncMaintenance;
use App\Models\WhatsappAccount;
use App\Services\OpenWaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'openwa.session_name' => 'whatsapp-sistemas',
        'openwa.recovery_sync_cooldown_minutes' => 15,
    ]);
    Cache::flush();
});

it('dispatches maintenance when OpenWA transitions from not ready to ready', function () {
    Queue::fake();

    WhatsappAccount::query()->create([
        'name' => 'whatsapp-sistemas',
        'status' => 'disconnected',
        'openwa_last_status' => 'unavailable',
    ]);

    bindOpenWaSession(['id' => 'session-1', 'name' => 'whatsapp-sistemas', 'status' => 'ready']);

    $this->artisan('whatsapp:monitor-openwa')
        ->expectsOutput('Sesión whatsapp-sistemas: ready.')
        ->expectsOutput('Sincronización de recuperación disparada.')
        ->assertSuccessful();

    Queue::assertPushed(RunWhatsappSyncMaintenance::class, function (RunWhatsappSyncMaintenance $job) {
        return $job->reason === 'openwa_recovered';
    });

    $account = WhatsappAccount::query()->where('name', 'whatsapp-sistemas')->firstOrFail();

    expect($account->openwa_last_status)->toBe('ready')
        ->and($account->openwa_last_checked_at)->not->toBeNull()
        ->and($account->openwa_last_ready_at)->not->toBeNull()
        ->and($account->openwa_last_recovery_sync_at)->not->toBeNull()
        ->and($account->openwa_recovery_sync_error)->toBeNull();
});

it('does not dispatch maintenance when OpenWA remains ready', function () {
    Queue::fake();

    WhatsappAccount::query()->create([
        'name' => 'whatsapp-sistemas',
        'status' => 'connected',
        'openwa_last_status' => 'ready',
        'openwa_last_recovery_sync_at' => now()->subHour(),
    ]);

    bindOpenWaSession(['id' => 'session-1', 'name' => 'whatsapp-sistemas', 'status' => 'ready']);

    $this->artisan('whatsapp:monitor-openwa')
        ->expectsOutput('Sesión whatsapp-sistemas: ready.')
        ->assertSuccessful();

    Queue::assertNotPushed(RunWhatsappSyncMaintenance::class);
});

it('does not dispatch repeated recovery sync while cooldown is active', function () {
    Queue::fake();

    WhatsappAccount::query()->create([
        'name' => 'whatsapp-sistemas',
        'status' => 'disconnected',
        'openwa_last_status' => 'unavailable',
        'openwa_last_recovery_sync_at' => now()->subMinutes(5),
    ]);

    bindOpenWaSession(['id' => 'session-1', 'name' => 'whatsapp-sistemas', 'status' => 'ready']);

    $this->artisan('whatsapp:monitor-openwa')
        ->expectsOutput('Sesión whatsapp-sistemas: ready.')
        ->expectsOutput('No se dispara sincronización de recuperación: cooldown activo.')
        ->assertSuccessful();

    Queue::assertNotPushed(RunWhatsappSyncMaintenance::class);
});

it('updates status and error when OpenWA is unavailable', function () {
    Queue::fake();

    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('findSessionByName')
        ->once()
        ->with('whatsapp-sistemas')
        ->andThrow(new RuntimeException('OpenWA caído'));
    $this->instance(OpenWaClient::class, $client);

    $this->artisan('whatsapp:monitor-openwa')
        ->expectsOutput('Sesión whatsapp-sistemas: unavailable.')
        ->assertSuccessful();

    Queue::assertNotPushed(RunWhatsappSyncMaintenance::class);

    $account = WhatsappAccount::query()->where('name', 'whatsapp-sistemas')->firstOrFail();

    expect($account->openwa_last_status)->toBe('unavailable')
        ->and($account->status)->toBe('disconnected')
        ->and($account->openwa_last_checked_at)->not->toBeNull()
        ->and($account->openwa_recovery_sync_error)->toContain('OpenWA caído');
});

/**
 * @param  array<string, mixed>|null  $session
 */
function bindOpenWaSession(?array $session): void
{
    $client = Mockery::mock(OpenWaClient::class);
    $client->shouldReceive('findSessionByName')
        ->once()
        ->with('whatsapp-sistemas')
        ->andReturn($session);

    test()->instance(OpenWaClient::class, $client);
}
