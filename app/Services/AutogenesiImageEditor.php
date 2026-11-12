<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AutogenesiImageEditor
{
    public const PROMPT = 'BACKGROUND REPLACEMENT ONLY. Isolate ONLY the existing radio display unit using its exact outer silhouette, including its own integral bezel. Keep exactly the same unit, original width-to-height ratio, perspective, colors, buttons and screen content. Remove everything outside that silhouette: the original background, vehicle dashboard, dashboard fascia, air vents, steering wheel, hazard switch, trim, cables, accessories, captions and advertising. These surrounding objects are NOT part of the display unit and must not appear in the output. Behind the isolated unit create ONLY a natural Canary Islands desert landscape: sand dunes and clear blue sky. No stone platform, pedestal, rock, table, stand, vehicle interior, invented support, extra hardware, accessories, people or additional objects. No writing, logos or watermarks in the background. Do not reconstruct a dashboard beneath or around the screen. Do not redesign or enhance the product or add any detail to it. The complete scene contains exactly one unchanged display unit and the desert background. Square canvas, no borders or padding.';

    public const GEOMETRY_PROMPT = 'HIGHEST PRIORITY: the supplied radio and its display are an immutable geometric reference. Preserve the exact ORIGINAL width-to-height ratio of the entire radio, the active display, bezel, fascia and every control. Never stretch, squash, widen, narrow, lengthen, shorten, warp or redesign the product on either axis. Resize the whole product with ONE uniform scale factor only, identical horizontally and vertically. Keep the original camera viewpoint, orientation and perspective; do not rotate the product or introduce a new viewing angle. A square OUTPUT CANVAS does NOT mean a square radio: a wide radio must remain equally wide relative to its height. Fit the complete unchanged product inside the frame and extend only the surrounding background to fill the square. Never fill the square by reshaping the screen, enlarging its height, reducing its width or cropping the product. Preserve all edges, screen corners, bezel thickness and relative button positions. If composition or styling conflicts with original product geometry, original product geometry takes priority.';

    public const DASHBOARD_CLEAN_PROMPT = 'Inpaint ONLY the transparent areas of the supplied mask. Use the surrounding pixels of the attached vehicle dashboard photograph, including its current crop, as the exact reference. Continue existing straight and curved lines, surfaces, color gradients, grain, reflections and shadows naturally through each masked area. Remove the selected overlaid text or logo by reconstructing the surface underneath. Do not change any unmasked pixel, physical radio, display, vents, buttons, controls, dashboard geometry, perspective or proportions. Do not add new objects, labels or logos. Return the same square composition with restored detail and natural sharpness.';
    public const ACCESSORIES_PROMPT = 'Recreate the attached ACCESSORIES LAYOUT as a clean product photograph on a pure white square background. Preserve every distinct visible accessory from the reference, including its exact type, shape, color, connector, cable, relative size, count and approximate position. Keep all accessories separated and fully visible. Remove the heading, advertising text, logos, watermarks and translucent lettering over the objects. Where a watermark covers a part, reconstruct only the obscured surface consistently with the visible part. Preserve useful accessory names if present, typeset clearly in black beneath the corresponding item; do not invent names. Do not add, remove, merge or duplicate any accessory. In particular, do not invent a radio, screen, head unit or dashboard if none is present in the reference. Do not enlarge any one object to dominate the composition. Use even lighting and sharp detail. No decorative elements, gray cards or borders.';

    public function edit(AutogenesiProduct $product, string $id, string $mode = 'scene', array $points = [], ?\Illuminate\Http\UploadedFile $mask = null): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Modifica immagini disponibile solo in locale.');
        }
        if (! config('services.openai.api_key')) {
            throw new RuntimeException('Chiave OpenAI non configurata.');
        }
        $image = collect($product->images)->firstWhere('sha256', $id);
        if (! $image) {
            throw new RuntimeException('La foto selezionata non è più presente.');
        }
        $original = $image['original'] ?? $image;
        $disk = Storage::disk('local');
        $path = $disk->path($mode === 'dashboard_clean' ? $image['path']
            : ((in_array($mode, ['white'], true) || $points || $mask) ? $image['path'] : ($original['uncropped_path'] ?? $original['path'])));
        $info = is_file($path) && ! is_link($path) ? @getimagesize($path) : false;
        if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true) || filesize($path) > 8 * 1024 * 1024) {
            throw new RuntimeException('Immagine originale non disponibile o non valida.');
        }
        $prompt = match ($mode) {
            'white' => self::ACCESSORIES_PROMPT,
            'dashboard_clean' => self::DASHBOARD_CLEAN_PROMPT,
            default => self::GEOMETRY_PROMPT."\n\n".self::PROMPT,
        };
        if ($mask) {
            $mi = @getimagesize($mask->getRealPath());
            if (!$mi || $mi['mime'] !== 'image/png' || $mi[0] !== $info[0] || $mi[1] !== $info[1] || $info[0] * $info[1] > 16000000) throw new RuntimeException('Maschera automatica non valida.');
        }
        if ($mode === 'dashboard_clean' && !$mask) throw new RuntimeException('Seleziona le zone da ricostruire.');
        $reference = $mode === 'dashboard_clean' ? app(AutogenesiReferenceInpaint::class)->prepare($path, $mask->getRealPath()) : null;
        if ($mode === 'scene') {
            $prompt = 'Create a photorealistic premium studio product-photography SET ONLY, with no product. Canary Islands desert setting: sand dunes, full sun, clear blue sky, editorial style. One broad natural stone platform in the foreground, with a flat horizontal top surface facing the camera. Square 1024x1024 full-bleed image. CRITICAL COMPOSITION: the platform spans almost the full image width, its flat top supports an object at horizontal center x=512, contact line y=760 pixels (74 percent from the top); stone top surface extends behind and in front of that contact line, with the front edge below y=800. Keep the central area above the platform clear for later compositing of the real product. Realistic warm stone texture and daylight. No radio, screen, dashboard, vehicle, accessories, other props, people, writing, lettering, logos or watermarks anywhere. Do not generate the product or its silhouette.';
            if ($points) {
                foreach ($points as $point) {
                    if (!array_is_list($point) || count($point) !== 2 || $point[0] < 0 || $point[1] < 0 || $point[0] >= $info[0] || $point[1] >= $info[1]) throw new RuntimeException('Contorno non valido.');
                }
            }
        }
        $accessoryReference = $mode === 'white' ? $this->accessoryReference($path) : null;
        $stream = in_array($mode, ['scene', 'dashboard_clean'], true) ? null : fopen($accessoryReference ?? $path, 'rb');
        set_time_limit(300);
        try {
            $request = Http::withToken(config('services.openai.api_key'))->acceptJson()->connectTimeout(15)->timeout(240);
            if ($reference) $request->attach('image', $reference['reference'], 'original-square.png', ['Content-Type' => 'image/png'])
                ->attach('mask', $reference['mask'], 'selection.png', ['Content-Type' => 'image/png']);
            elseif ($mode !== 'scene') $request->attach('image', $stream, $mode === 'white' ? 'accessories.png' : basename($path), ['Content-Type' => $mode === 'white' ? 'image/png' : $info['mime']]);
            $data = $request->post('https://api.openai.com/v1/images/'.($mode === 'scene' ? 'generations' : 'edits'), [
                'model' => config('services.openai.image_model', 'gpt-image-1-mini'), 'prompt' => $prompt,
                'size' => '1024x1024', 'quality' => 'high', 'output_format' => 'png', 'n' => 1,
            ])->throw()->json();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($accessoryReference) @unlink($accessoryReference);
        }
        app(ProductSheetProcessing::class)->check();
        $encoded = data_get($data, 'data.0.b64_json');
        $bytes = is_string($encoded) && strlen($encoded) <= 24 * 1024 * 1024 ? base64_decode($encoded, true) : false;
        $result = $bytes && strlen($bytes) <= 16 * 1024 * 1024 ? @getimagesizefromstring($bytes) : false;
        if (! $result || $result['mime'] !== 'image/png' || $result[0] !== 1024 || $result[1] !== 1024) {
            throw new RuntimeException('Il servizio AI non ha restituito una foto quadrata valida.');
        }
        if ($reference) $bytes = app(AutogenesiReferenceInpaint::class)->merge($reference['reference'], $reference['mask'], $bytes);
        if ($mode === 'scene') $bytes = app(AutogenesiSceneComposite::class)->compose($path, $bytes, $points, $mask?->getRealPath());
        $target = 'autogenesi/products/'.$product->source_id.'/ai-'.Str::uuid().'.png';
        if (! $disk->put($target, $bytes)) {
            throw new RuntimeException('Salvataggio immagine AI non riuscito.');
        }
        try {
            DB::transaction(function () use ($product, $id, $original, $target, $bytes, $prompt, $mode) {
                $current = $product->locked();
                $images = $current->images;
                $index = array_search($id, array_column($images, 'sha256'), true);
                if ($index === false) {
                    throw new RuntimeException('La foto è cambiata durante la modifica.');
                }
                $images[$index] = array_replace($images[$index], ['path' => $target, 'width' => 1024, 'height' => 1024,
                    'sha256' => hash('sha256', $bytes), 'original' => $original, 'ai_edited' => true, 'image_reframed' => $mode === 'white',
                    'ai_prompt' => $prompt, 'ai_edit_mode' => $mode, 'ai_model' => config('services.openai.image_model'), 'ai_edited_at' => now()->toIso8601String()]);
                unset($images[$index]['shopify_file_reference'], $images[$index]['shopify_url'], $images[$index]['public_url']);
                $current->update(['images' => $images]);
            });
        } catch (\Throwable $e) {
            $disk->delete($target);
            throw $e;
        }
        if ((($image['ai_edited'] ?? false) || ($image['manually_cropped'] ?? false) || ($image['manually_painted'] ?? false)) && $image['path'] !== $original['path']) {
            $disk->delete($image['path']);
        }
    }

    private function accessoryReference(string $path): string
    {
        $info = getimagesize($path);
        if ($info[0] * $info[1] > 16000000) throw new RuntimeException('Immagine accessori troppo grande.');
        $source = @imagecreatefromstring(file_get_contents($path));
        if (!$source) throw new RuntimeException('Immagine accessori non leggibile.');
        $size = 1024;
        $canvas = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        $scale = min($size / imagesx($source), $size / imagesy($source));
        $w = max(1, (int) round(imagesx($source) * $scale));
        $h = max(1, (int) round(imagesy($source) * $scale));
        imagecopyresampled($canvas, $source, (int) floor(($size - $w) / 2), (int) floor(($size - $h) / 2), 0, 0, $w, $h, imagesx($source), imagesy($source));
        $cleanup = app(AutogenesiAccessoryCleanup::class);
        $cleaned = $cleanup->clean($canvas);
        if (!$cleaned && ($cut = $cleanup->headerCut($source)) !== null) {
            imagefill($canvas, 0, 0, $white);
            $cropHeight = imagesy($source) - $cut;
            $scale = min($size / imagesx($source), $size / $cropHeight);
            $w = max(1, (int) round(imagesx($source) * $scale));
            $h = max(1, (int) round($cropHeight * $scale));
            imagecopyresampled($canvas, $source, (int) floor(($size - $w) / 2), (int) floor(($size - $h) / 2), 0, 0, $w, $h, imagesx($source), $cropHeight);
        }
        ob_start();
        imagepng($canvas, null, 6);
        $bytes = ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);
        if (!$bytes) throw new RuntimeException('Preparazione foto accessori non riuscita.');
        $temporary = tempnam(sys_get_temp_dir(), 'autogenesi-accessories-');
        if (!$temporary || file_put_contents($temporary, $bytes) === false) throw new RuntimeException('Preparazione foto accessori non riuscita.');
        return $temporary;
    }

    public function restore(AutogenesiProduct $product, string $id): void
    {
        $path = DB::transaction(function () use ($product, $id) {
            $current = $product->locked();
            $images = $current->images;
            $index = array_search($id, array_column($images, 'sha256'), true);
            if ($index === false || empty($images[$index]['original'])) {
                throw new RuntimeException('Originale non disponibile.');
            }
            $image = $images[$index];
            if (! Storage::disk('local')->exists($image['original']['path'])) {
                throw new RuntimeException('File originale non disponibile.');
            }
            $images[$index] = $image['original'];
            if (array_key_exists('dashboard_selected', $image)) $images[$index]['dashboard_selected'] = $image['dashboard_selected'];
            if (isset($image['shopify_file_reference']) && $image['sha256'] === $image['original']['sha256']) $images[$index]['shopify_file_reference'] = $image['shopify_file_reference'];
            $current->update(['images' => $images]);

            return $image['path'];
        });
        Storage::disk('local')->delete($path);
    }
}
