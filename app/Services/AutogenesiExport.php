<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\Storage;

class AutogenesiExport
{
    public function writeFiles(AutogenesiProduct $p): array
    {
        if ($p instanceof \App\Models\CatalogProductSheet) return [];
        $disk = Storage::disk('local');
        $dir = 'autogenesi/exports/'.$p->id;
        $disk->makeDirectory($dir);
        $files = ['excel' => $dir.'/'.$p->handle.'-autogenesi.xlsx'];
        $this->xlsx($p, $disk->path($files['excel']));
        $disk->delete([$dir.'/'.$p->handle.'-shopify-draft.csv', $dir.'/'.$p->handle.'-catalogo.csv', $dir.'/'.$p->handle.'-catalogo.xlsx']);

        return ['excel' => $disk->path($files['excel'])];
    }

    public function rows(AutogenesiProduct $p): array
    {
        return app(AutogenesiTemplateExport::class)->rows($p);
    }

    public function xlsx(AutogenesiProduct $p, string $path): void
    {
        app(AutogenesiTemplateExport::class)->write($p, $path);
    }
}
