<?php

namespace App\Services;

class AutogenesiAccessoryCleanup
{
    public function headerCut(\GdImage $image): ?int
    {
        $w = imagesx($image); $h = imagesy($image);
        if ($w < 300 || $h < 300) return null;
        $ink = [];
        for ($y = 0; $y < (int) ($h * .32); $y += 3) {
            $count = 0;
            for ($x = 0; $x < $w; $x += 3) {
                [$light] = $this->tone(imagecolorat($image, $x, $y));
                if ($light < 210) $count++;
            }
            $ink[$y] = $count / max(1, (int) ceil($w / 3));
        }
        $head = array_filter($ink, fn ($ratio, $y) => $y < $h * .14 && $ratio > .03 && $ratio < .35, ARRAY_FILTER_USE_BOTH);
        if (count($head) < 3) return null;
        $lastText = max(array_keys($head));
        for ($y = $lastText + 3; $y < $h * .25; $y += 3) {
            if (($ink[$y] ?? 1) < .015 && ($ink[$y + 3] ?? 1) < .015) {
                return min($h - 1, $y + 6);
            }
        }
        return null;
    }

    public function clean(\GdImage $image): bool
    {
        $w = imagesx($image); $h = imagesy($image); $tones = []; $samples = 0;
        for ($y = 0; $y < $h; $y += 4) for ($x = 0; $x < $w; $x += 4) {
            [$light, $spread] = $this->tone(imagecolorat($image, $x, $y));
            if ($spread > 12 || $light < 120 || $light > 240) continue;
            $tones[(int) round($light / 4) * 4] = ($tones[(int) round($light / 4) * 4] ?? 0) + 1;
            $samples++;
        }
        arsort($tones);
        $major = array_key_first($tones);
        $minor = null;
        foreach ($tones as $tone => $count) {
            if (abs($tone - $major) >= 24 && $count >= $samples * .02) { $minor = $tone; break; }
        }
        if ($major === null || $minor === null || ($tones[$major] ?? 0) < $samples * .1) return false;
        $background = max($major, $minor); $caption = min($major, $minor);
        if ($background < 190 || $caption > 200) return false;

        $cols = array_fill(0, $w, 0); $rows = array_fill(0, $h, 0);
        for ($y = 0; $y < $h; $y += 2) for ($x = 0; $x < $w; $x += 2) {
            [$light, $spread] = $this->tone(imagecolorat($image, $x, $y));
            if ($spread <= 12 && (abs($light - $background) <= 11 || abs($light - $caption) <= 11)) {
                $cols[$x]++; $rows[$y]++;
            }
        }
        $xs = $this->runs($cols, $h * .07, $w * .08);
        $ys = $this->runs($rows, $w * .09, $h * .05);
        if (!$xs || !$ys || count($xs) * count($ys) < 4) return false;

        $white = imagecolorallocate($image, 255, 255, 255);
        foreach ($ys as [$top, $bottom]) foreach ($xs as [$left, $right]) {
            $footer = null;
            for ($y = $top + (int) (($bottom - $top) * .55); $y <= $bottom - 5; $y += 2) {
                $hits = 0;
                for ($x = $left + 8; $x <= $right - 8; $x += 3) {
                    [$light, $spread] = $this->tone(imagecolorat($image, $x, $y));
                    if ($spread <= 12 && abs($light - $caption) <= 11) $hits++;
                }
                if ($hits >= (($right - $left - 16) / 3) * .55) { $footer = $y; break; }
            }
            for ($y = $top; $y <= $bottom; $y++) for ($x = $left; $x <= $right; $x++) {
                [$light, $spread] = $this->tone(imagecolorat($image, $x, $y));
                if ($spread > 12) continue;
                if (abs($light - $background) <= 11 || ($footer !== null && $y >= $footer && abs($light - $caption) <= 11)) {
                    imagesetpixel($image, $x, $y, $white);
                } elseif ($footer !== null && $y >= $footer && $x >= $left + 8 && $x <= $right - 8
                    && $y <= $bottom - 7 && $light >= $caption + 12) {
                    $dark = max(0, min(255, (int) round(255 * (255 - $light) / (255 - $caption - 12))));
                    imagesetpixel($image, $x, $y, ($dark << 16) | ($dark << 8) | $dark);
                }
            }
        }
        return true;
    }

    private function tone(int $color): array
    {
        $r = ($color >> 16) & 255; $g = ($color >> 8) & 255; $b = $color & 255;
        return [(int) round(($r + $g + $b) / 3), max($r, $g, $b) - min($r, $g, $b)];
    }

    private function runs(array $values, float $threshold, float $min): array
    {
        $runs = []; $start = null; $last = -1;
        foreach ($values as $i => $value) {
            if ($value >= $threshold) { $start ??= $i; $last = $i; }
            elseif ($start !== null && $i - $last > 4) {
                if ($last - $start >= $min) $runs[] = [$start, $last];
                $start = null;
            }
        }
        if ($start !== null && $last - $start >= $min) $runs[] = [$start, $last];
        return $runs;
    }
}
