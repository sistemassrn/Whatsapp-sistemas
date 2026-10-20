<?php

namespace App\Services;

enum WhatsappMessageImportSource: string
{
    case Webhook = 'webhook';
    case HistoricalSync = 'historical_sync';
}
