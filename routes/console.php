<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('openwa.sync_recent.enabled')) {
    Schedule::command(sprintf(
        'whatsapp:sync-recent --limit-chats=%d --limit-messages=%d',
        config('openwa.sync_recent.limit_chats'),
        config('openwa.sync_recent.limit_messages'),
    ))
        ->everyFiveMinutes()
        ->withoutOverlapping(10);
}

if (config('openwa.retry_media.enabled')) {
    Schedule::command(sprintf(
        'whatsapp:retry-media --limit=%d --minutes=%d',
        config('openwa.retry_media.limit'),
        config('openwa.retry_media.minutes'),
    ))
        ->everyFiveMinutes()
        ->withoutOverlapping(10);
}
