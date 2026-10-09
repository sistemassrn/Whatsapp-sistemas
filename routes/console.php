<?php

use App\Jobs\RunWhatsappSyncMaintenance;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RunWhatsappSyncMaintenance('scheduled'))
    ->dailyAt('07:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->withoutOverlapping();

Schedule::command('whatsapp:retry-media', [
    '--limit' => (int) config('openwa.retry_media.limit', 50),
    '--minutes' => (int) config('openwa.retry_media.minutes', 1440),
])
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('whatsapp:monitor-openwa')
    ->everyTwoMinutes()
    ->withoutOverlapping();
