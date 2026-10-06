<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:sync-maintenance')]
#[Description('Run WhatsApp recent sync and media retry maintenance')]
class SyncWhatsappMaintenance extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Iniciando mantenimiento de sincronización de WhatsApp.');

        $syncResult = $this->call('whatsapp:sync-recent', [
            '--limit-chats' => config('openwa.sync_recent.limit_chats'),
            '--limit-messages' => config('openwa.sync_recent.limit_messages'),
        ]);

        if ($syncResult !== self::SUCCESS) {
            $this->error("whatsapp:sync-recent falló con código {$syncResult}.");

            return self::FAILURE;
        }

        $this->info('Sincronización reciente completada. Reintentando medios pendientes.');

        $retryResult = $this->call('whatsapp:retry-media', [
            '--limit' => config('openwa.retry_media.limit'),
            '--minutes' => config('openwa.retry_media.minutes'),
        ]);

        if ($retryResult !== self::SUCCESS) {
            $this->error("whatsapp:retry-media falló con código {$retryResult}.");

            return self::FAILURE;
        }

        $this->info('Mantenimiento de sincronización de WhatsApp completado.');

        return self::SUCCESS;
    }
}
