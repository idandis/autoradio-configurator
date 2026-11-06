<?php

namespace App\Services;

use RuntimeException;

class AutogenesiSceneComposite
{
    public const CONTACT_Y = 760;

    public function compose(string $path, string $background, array $points, ?string $maskPath = null): string
    {
        $info = @getimagesize($path);
        if (!$info || $info[0] * $info[1] > 16000000) throw new RuntimeException('Foto troppo grande per lo scontorno.');
        [$w, $h] = $info;
        if (!$points) $points = [[0, 0], [$w - 1, 0], [$w - 1, $h - 1], [0, $h - 1]];
        if (count($points) < 3 || count($points) > 100) throw new RuntimeException('Seleziona il contorno della pantalla.');
        $flat = [];
        foreach ($points as $point) {
            if (!array_is_list($point) || count($point) !== 2 || !is_numeric($point[0]) || !is_numeric($point[1])
                || $point[0] < 0 || $point[1] < 0 || $point[0] >= $w || $point[1] >= $h) {
                throw new RuntimeException('Il contorno deve rimanere dentro la foto.');
            }
            $flat[] = (int) round($point[0]); $flat[] = (int) round($point[1]);
        }
        $source = imagecreatefromstring(file_get_contents($path));
        imagepalettetotruecolor($source);
        if ($maskPath) {
            $mi = @getimagesize($maskPath);
            if (!$mi || $mi['mime'] !== 'image/png' || $mi[0] !== $w || $mi[1] !== $h || filesize($maskPath) > 8 * 1024 * 1024) throw new RuntimeException('Maschera automatica non valida.');
            $mask = imagecreatefrompng($maskPath);
            imagepalettetotruecolor($mask);
            $x = $w; $y = $h; $right = -1; $bottom = -1;
            for ($my = 0; $my < $h; $my++) for ($mx = 0; $mx < $w; $mx++) {
                if ((imagecolorat($mask, $mx, $my) & 0xff) > 127) {
                    $x = min($x, $mx); $y = min($y, $my); $right = max($right, $mx); $bottom = max($bottom, $my);
                }
            }
            $cw = $right - $x + 1; $ch = $bottom - $y + 1;
        } else {
            $x = min(array_column($points, 0)); $y = min(array_column($points, 1));
            $cw = (int) (max(array_column($points, 0)) - $x + 1);
            $ch = (int) (max(array_column($points, 1)) - $y + 1);
            $mask = imagecreatetruecolor($w, $h);
            imagefilledpolygon($mask, $flat, imagecolorallocate($mask, 255, 255, 255));
        }
        if ($cw < 16 || $ch < 16) throw new RuntimeException('Contorno troppo piccolo.');
        $cut = imagecreatetruecolor($cw, $ch);
        imagealphablending($cut, false); imagesavealpha($cut, true);
        imagefill($cut, 0, 0, imagecolorallocatealpha($cut, 0, 0, 0, 127));
        for ($py = 0; $py < $ch; $py++) {
            for ($px = 0; $px < $cw; $px++) {
                if ((imagecolorat($mask, $px + (int) $x, $py + (int) $y) & 0xff) > 127) {
                    imagesetpixel($cut, $px, $py, imagecolorat($source, $px + (int) $x, $py + (int) $y));
                }
            }
        }
        $result = imagecreatefromstring($background);
        $scale = min(860 / $cw, 700 / $ch);
        $dw = max(1, (int) round($cw * $scale)); $dh = max(1, (int) round($ch * $scale));
        imagealphablending($result, true);
        for ($r = 18; $r >= 1; $r--) {
            imagefilledellipse($result, 512, self::CONTACT_Y + 3, max(1, $dw - 20 + $r * 3), $r * 2,
                imagecolorallocatealpha($result, 25, 18, 10, 120));
        }
        imagecopyresampled($result, $cut, (int) ((1024 - $dw) / 2), self::CONTACT_Y - $dh, 0, 0, $dw, $dh, $cw, $ch);
        ob_start(); imagepng($result); $bytes = ob_get_clean();
        foreach ([$source, $mask, $cut, $result] as $im) imagedestroy($im);
        return $bytes;
    }
}
