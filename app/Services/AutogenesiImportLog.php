<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AutogenesiImportLog
{
    public function start(string $url): string
    {
        $id = (string) Str::uuid();
        $disk = Storage::disk('local');
        $disk->makeDirectory('autogenesi/diagnostics');
        $disk->put('autogenesi/diagnostics/active', $id);
        $disk->put('autogenesi/diagnostics/import.log', '');
        $this->write($id, 'avvio', ['url' => strtok($url, '?')]);
        return $id;
    }

    public function write(string $id, string $phase, array $details = []): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists('autogenesi/diagnostics/active') || trim($disk->get('autogenesi/diagnostics/active')) !== $id) return;
        $line = json_encode(['time' => now()->toIso8601String(), 'phase' => $phase, 'details' => $details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $line = preg_replace('/(?:Bearer\s+\S+|sk-[a-zA-Z0-9_-]+)/i', '[redacted]', $line);
        file_put_contents($disk->path('autogenesi/diagnostics/import.log'), $line."\n", FILE_APPEND | LOCK_EX);
    }

    public function directory(): string
    {
        return Storage::disk('local')->path('autogenesi/diagnostics');
    }
}
