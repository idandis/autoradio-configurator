<?php

namespace App\Console\Commands;

use App\Services\WhatsAppHealthMonitor;
use Illuminate\Console\Command;

class CheckWhatsAppHealth extends Command
{
    protected $signature = 'whatsapp:check-health';
    protected $description = 'Notify the administrator about WhatsApp session or bot outages';

    public function handle(WhatsAppHealthMonitor $monitor): int
    {
        $this->info($monitor->check());

        return self::SUCCESS;
    }
}
