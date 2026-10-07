<?php

use App\Jobs\RunWhatsappSyncMaintenance;
use App\Models\WhatsappAccount;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutMiddleware(Authenticate::class);
    config(['openwa.session_name' => 'whatsapp-sistemas']);
    Cache::flush();
});

it('dispatches the maintenance sync job from the manual endpoint', function () {
    Queue::fake();

    $this->post(route('whatsapp.sync-maintenance'))
        ->assertRedirect()
        ->assertSessionHas('success', 'Sincronización de mantenimiento iniciada.');

    Queue::assertPushed(RunWhatsappSyncMaintenance::class);
});

it('does not dispatch a duplicate sync while the lock is held', function () {
    Queue::fake();

    $lock = Cache::lock(RunWhatsappSyncMaintenance::lockName(), RunWhatsappSyncMaintenance::LOCK_SECONDS);
    $lock->get();

    try {
        $this->post(route('whatsapp.sync-maintenance'))
            ->assertRedirect()
            ->assertSessionHas('error', 'Ya hay una sincronización en curso.');

        Queue::assertNotPushed(RunWhatsappSyncMaintenance::class);
    } finally {
        $lock->release();
    }
});

it('does not dispatch a duplicate sync while the persisted status is fresh running', function () {
    Queue::fake();

    WhatsappAccount::query()->create([
        'name' => 'whatsapp-sistemas',
        'status' => 'ready',
        'maintenance_status' => 'running',
        'maintenance_started_at' => now(),
    ]);

    $this->post(route('whatsapp.sync-maintenance'))
        ->assertRedirect()
        ->assertSessionHas('error', 'Ya hay una sincronización en curso.');

    Queue::assertNotPushed(RunWhatsappSyncMaintenance::class);
});

it('persists successful maintenance job status', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('whatsapp:sync-maintenance')
        ->andReturn(0);
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('Mantenimiento completado.');

    (new RunWhatsappSyncMaintenance)->handle();

    $account = WhatsappAccount::query()->where('name', 'whatsapp-sistemas')->firstOrFail();

    expect($account->maintenance_status)->toBe('success')
        ->and($account->maintenance_started_at)->not->toBeNull()
        ->and($account->maintenance_finished_at)->not->toBeNull()
        ->and($account->maintenance_error)->toBeNull();
});

it('persists failed maintenance job status', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->with('whatsapp:sync-maintenance')
        ->andReturn(1);
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('Falló el mantenimiento.');

    (new RunWhatsappSyncMaintenance)->handle();

    $account = WhatsappAccount::query()->where('name', 'whatsapp-sistemas')->firstOrFail();

    expect($account->maintenance_status)->toBe('failed')
        ->and($account->maintenance_started_at)->not->toBeNull()
        ->and($account->maintenance_finished_at)->not->toBeNull()
        ->and($account->maintenance_error)->toContain('Falló el mantenimiento.');
});
