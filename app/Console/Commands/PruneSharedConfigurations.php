<?php

namespace App\Console\Commands;

use App\Models\SharedConfiguration;
use Illuminate\Console\Command;

class PruneSharedConfigurations extends Command
{
    protected $signature = 'shared-configurations:prune';

    protected $description = 'Delete shared configurations older than 30 days';

    public function handle(): int
    {
        $deleted = SharedConfiguration::query()
            ->where('created_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Deleted {$deleted} expired shared configurations.");

        return self::SUCCESS;
    }
}
