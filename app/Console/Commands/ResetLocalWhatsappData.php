<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('whatsapp:reset-local-data {--confirm= : Must be RESET_WHATSAPP_LOCAL_DATA} {--dry-run : Show counts without deleting}')]
#[Description('Reset local WhatsApp persistence before an initial sync')]
class ResetLocalWhatsappData extends Command
{
    private const CONFIRMATION = 'RESET_WHATSAPP_LOCAL_DATA';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && $this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Confirmation required. This command deletes local WhatsApp data only.');
            $this->line('Run: php artisan whatsapp:reset-local-data --confirm=RESET_WHATSAPP_LOCAL_DATA');
            $this->line('Preview first: php artisan whatsapp:reset-local-data --dry-run');

            return self::FAILURE;
        }

        $counts = $this->counts();

        $this->info('Local WhatsApp data counts:');

        foreach ($counts as $table => $count) {
            $this->line("{$table}: {$count}");
        }

        if ($dryRun) {
            $this->info('Dry run only. No data was deleted.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            DB::table('conversations')->update([
                'last_message_id' => null,
                'last_message_at' => null,
            ]);

            DB::table('messages')->delete();
            DB::table('conversations')->delete();
            DB::table('contacts')->delete();
            DB::table('whatsapp_accounts')->delete();
        });

        $this->info('Deleted local WhatsApp data successfully.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'messages' => DB::table('messages')->count(),
            'conversations' => DB::table('conversations')->count(),
            'contacts' => DB::table('contacts')->count(),
            'whatsapp_accounts' => DB::table('whatsapp_accounts')->count(),
        ];
    }
}
