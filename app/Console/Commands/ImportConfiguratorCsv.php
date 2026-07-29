<?php

namespace App\Console\Commands;

use App\Services\ConfiguratorCsvImporter;
use Illuminate\Console\Command;

class ImportConfiguratorCsv extends Command
{
    protected $signature = 'configurator:import-csv
                            {path : Absolute path to the Shopify CSV export}
                            {--add : Preserve products not included in this file}';

    protected $description = 'Importa un export Shopify CSV e genera il dataset del configuratore.';

    public function handle(ConfiguratorCsvImporter $importer): int
    {
        $stats = $importer->import(
            (string) $this->argument('path'),
            replaceExistingDataset: ! $this->option('add'),
        );

        $this->info(sprintf(
            'Import completato: %d schermi, %d camere, %d altoparlanti, %d installazioni, %d varianti. Foto mancanti: %d; messe in coda: %d.',
            $stats['screen_products'],
            $stats['camera_products'],
            $stats['speaker_products'],
            $stats['installation_products'],
            $stats['variants'],
            $stats['vehicle_images_missing'],
            $stats['vehicle_images_queued'],
        ));

        return self::SUCCESS;
    }
}
