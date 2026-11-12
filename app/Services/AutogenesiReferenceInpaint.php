<?php

namespace App\Services;

use RuntimeException;

class AutogenesiReferenceInpaint
{
    public function prepare(string $path, string $maskPath): array
    {
        $source = @imagecreatefromstring(file_get_contents($path));
        $mask = @imagecreatefrompng($maskPath);
        if (!$source || !$mask) throw new RuntimeException('Foto o selezione non valida.');
        $w = imagesx($source); $h = imagesy($source);
        if (imagesx($mask) !== $w || imagesy($mask) !== $h) {
            imagedestroy($source); imagedestroy($mask);
            throw new RuntimeException('La selezione non corrisponde alla foto originale. Ripristina l’originale e riprova.');
        }
        $size = 1024; $scale = min($size / $w, $size / $h);
        $fw = max(1, (int) round($w * $scale)); $fh = max(1, (int) round($h * $scale));
        $x = intdiv($size - $fw, 2); $y = intdiv($size - $fh, 2);
        $square = imagecreatetruecolor($size, $size);
        $crop = min($w, $h); $sx = intdiv($w - $crop, 2); $sy = intdiv($h - $crop, 2);
        imagecopyresampled($square, $source, 0, 0, $sx, $sy, $size, $size, $crop, $crop);
        for ($i = 0; $i < 8; $i++) imagefilter($square, IMG_FILTER_GAUSSIAN_BLUR);
        imagecopyresampled($square, $source, $x, $y, 0, 0, $fw, $fh, $w, $h);

        $selection = imagecreatetruecolor($size, $size);
        imagefill($selection, 0, 0, imagecolorallocate($selection, 0, 0, 0));
        imagecopyresized($selection, $mask, $x, $y, 0, 0, $fw, $fh, $w, $h);
        $apiMask = imagecreatetruecolor($size, $size);
        imagealphablending($apiMask, false); imagesavealpha($apiMask, true);
        $solid = imagecolorallocatealpha($apiMask, 255, 255, 255, 0);
        $clear = imagecolorallocatealpha($apiMask, 255, 255, 255, 127);
        imagefill($apiMask, 0, 0, $solid);
        $selected = 0;
        for ($j = $y; $j < $y + $fh; $j++) for ($i = $x; $i < $x + $fw; $i++) {
            if ((imagecolorat($selection, $i, $j) & 255) <= 127) continue;
            imagesetpixel($apiMask, $i, $j, $clear); $selected++;
        }
        imagedestroy($source); imagedestroy($mask); imagedestroy($selection);
        if (!$selected) { imagedestroy($square); imagedestroy($apiMask); throw new RuntimeException('Seleziona almeno una scritta o un logo da ricostruire.'); }
        ob_start(); imagepng($square); $reference = ob_get_clean();
        ob_start(); imagepng($apiMask); $maskPng = ob_get_clean();
        imagedestroy($square); imagedestroy($apiMask);
        return ['reference' => $reference, 'mask' => $maskPng];
    }

    public function merge(string $reference, string $mask, string $generated): string
    {
        $base = @imagecreatefromstring($reference);
        $selection = @imagecreatefromstring($mask);
        $result = @imagecreatefromstring($generated);
        if (!$base || !$selection || !$result || imagesx($result) !== 1024 || imagesy($result) !== 1024) {
            throw new RuntimeException('Ricostruzione AI non valida.');
        }
        for ($y = 0; $y < 1024; $y++) for ($x = 0; $x < 1024; $x++) {
            if ((imagecolorat($selection, $x, $y) & 0x7f000000) === 0) continue;
            imagesetpixel($base, $x, $y, imagecolorat($result, $x, $y));
        }
        ob_start(); imagepng($base); $bytes = ob_get_clean();
        imagedestroy($base); imagedestroy($selection); imagedestroy($result);
        return $bytes;
    }
}
