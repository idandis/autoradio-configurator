<?php

namespace App\Console\Commands;

use App\Services\ConfiguratorCsvImporter;
use Illuminate\Console\Command;

class ImportConfiguratorCsv extends Command
{
    protected $signature = 'configurator:import-csv {path : Absolute path to the Shopify CSV export}';

    protected $description = 'Importa un export Shopify CSV e genera il dataset del configuratore.';

    public function handle(ConfiguratorCsvImporter $importer): int
    {
        $stats = $importer->import((string) $this->argument('path'));

        $this->info(sprintf(
            'Import completato: %d schermi, %d camere, %d installazioni, %d varianti.',
            $stats['screen_products'],
            $stats['camera_products'],
            $stats['installation_products'],
            $stats['variants'],
        ));

        return self::SUCCESS;
    }
}
