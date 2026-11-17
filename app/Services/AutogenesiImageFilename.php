<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AutogenesiImageFilename
{
    public function catalogPath(string $sourceId, array $image, string $extension): string
    {
        $dir = 'autogenesi/catalog/'.$sourceId.'/';
        $hash = $image['sha256'];
        $name = $this->stem($image['original_name'] ?? '');
        if ($name === '') {
            return $dir.$hash.'.'.$extension;
        }

        $path = $dir.$name.'.'.$extension;
        $public = Storage::disk('public');
        if (! $public->exists($path) || hash_file('sha256', $public->path($path)) === $hash) {
            return $path;
        }

        return $dir.$this->withSuffix($name, substr($hash, 0, 12)).'.'.$extension;
    }

    public function uploadPath(string $sourceId, string $originalName, string $extension): string
    {
        $name = $this->stem($originalName);
        if ($name === '') {
            $name = 'foto';
        }

        return 'autogenesi/products/'.$sourceId.'/'.$this->withSuffix($name, Str::random(12)).'.'.$extension;
    }

    private function stem(string $originalName): string
    {
        $name = pathinfo(basename(str_replace('\\', '/', $originalName)), PATHINFO_FILENAME);
        $name = preg_replace('/[^\pL\pN._-]+/u', '-', $name) ?? '';

        return trim(mb_substr($name, 0, 120), '.-_');
    }

    private function withSuffix(string $name, string $suffix): string
    {
        if (preg_match('/^(.*)(-[A-Z])$/', $name, $parts)) {
            return $parts[1].'-'.$suffix.$parts[2];
        }

        return $name.'-'.$suffix;
    }
}
