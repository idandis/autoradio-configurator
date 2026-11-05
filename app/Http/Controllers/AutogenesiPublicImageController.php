<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AutogenesiPublicImageController extends Controller
{
    public function __invoke(Request $request, string $source, string $filename): BinaryFileResponse
    {
        $disk = Storage::disk('public');
        $root = realpath($disk->path('autogenesi/catalog'));
        $path = realpath($disk->path('autogenesi/catalog/'.$source.'/'.$filename));
        abort_unless($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path), 404);
        $info = @getimagesize($path);
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($filename, PATHINFO_EXTENSION)] ?? null;
        abort_unless($mime && ($info['mime'] ?? '') === $mime && filesize($path) <= 16 * 1024 * 1024
            && hash_equals(pathinfo($filename, PATHINFO_FILENAME), hash_file('sha256', $path)), 404);
        $response = response()->file($path, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff']);
        $response->setPublic()->setMaxAge(86400)->setEtag(pathinfo($filename, PATHINFO_FILENAME));
        $response->isNotModified($request);

        return $response;
    }
}
