<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ProductSheetProcessing
{
    private ?array $active = null;

    public function epoch(string $kind, int $id): string
    {
        $path = Storage::disk('local')->path('autogenesi/processing/'.$kind.'-'.$id.'.json');
        clearstatcache(true, $path);
        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['epoch'] : 'initial';
    }

    public function begin(string $kind, int $id, ?string $epoch): void
    {
        $this->active = [$kind, $id, $epoch ?? $this->epoch($kind, $id)];
        $this->check();
    }

    public function clear(): void
    {
        $this->active = null;
    }

    public function check(): void
    {
        if ($this->active && $this->epoch($this->active[0], $this->active[1]) !== $this->active[2]) {
            throw new \App\Exceptions\ProductSheetCancelled;
        }
    }

    public function stop(string $kind, int $id): string
    {
        $disk = Storage::disk('local'); $disk->makeDirectory('autogenesi/processing');
        $epoch = (string) Str::uuid();
        $path = $disk->path('autogenesi/processing/'.$kind.'-'.$id.'.json'); $tmp = $path.'.'.Str::uuid();
        try {
            if (file_put_contents($tmp, json_encode(['epoch' => $epoch]), LOCK_EX) === false) throw new RuntimeException('Arresto non riuscito.');
            chmod($tmp, 0600);
            if (!rename($tmp, $path)) throw new RuntimeException('Arresto non riuscito.');
        } finally { if (is_file($tmp)) unlink($tmp); }
        return $epoch;
    }
}
