<?php

namespace App\Console\Commands;

use App\Services\AutogenesiExport;
use App\Services\AutogenesiImport;
use Illuminate\Console\Command;

class ImportAutogenesiProduct extends Command
{
    protected $signature = 'autogenesi:import {source : URL AliExpress oppure manifest JSON locale} {--brand=} {--model=} {--from=} {--to=}';

    protected $description = 'Importa un prodotto AliExpress in AutoGenesi come bozza privata';

    public function handle(AutogenesiImport $importer): int
    {
        try {
            $vehicle = ['brand' => $this->option('brand'), 'model' => $this->option('model'),
                'year_from' => $this->option('from'), 'year_to' => $this->option('to')];
            $source = $this->argument('source');
            $p = str_starts_with($source, 'https://') ? $importer->capture($source, $vehicle) : $importer->import($source, $vehicle);
            $files = app(AutogenesiExport::class)->writeFiles($p);
            $this->info('Bozza #'.$p->id.' — '.$p->title.' — '.count($p->variants).' varianti, '.count($p->images).' immagini.');
            foreach ($files as $name => $path) {
                $this->line($name.': '.$path);
            }
            $this->line(url('/autogenesi'));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
