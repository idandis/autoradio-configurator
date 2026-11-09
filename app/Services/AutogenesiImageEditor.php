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

    public const WHITE_PROMPT = 'Edit this packing-list product image into a clean square catalog layout on a pure white #FFFFFF background. Preserve every actual product and accessory shown: the radio, fascia, wiring harnesses, plugs, tools, antennas and all other supplied components. Keep their exact shapes, colors, proportions, connector details and quantities; do not invent, duplicate or remove any item. Remove the decorative background, colored panels, frames, grid, shadows from the old setting, arrows and advertising headers. Recompose the same items neatly on white, all fully visible. Preserve every accessory caption and technical qualifier verbatim, including optional-version restrictions, and place each caption in crisp black text immediately below its corresponding item. Do not add new text or features. The result must be a single square 1024x1024 image with a pure white background, not transparent.';

    public const CLEAN_PROMPT = 'Clean and enhance this vehicle dashboard or product photograph. Remove added advertising text, promotional graphics, arrows, decorative borders, frames and graphic overlays. Improve definition, focus, clarity and compression noise naturally without oversharpening or changing the subject. Preserve the exact dashboard, radio, screen geometry, vents, physical buttons, steering wheel, trim, materials, colors and proportions. Keep the original camera viewpoint and all visible hardware; do not replace the radio, invent controls, change the vehicle model or add accessories. Preserve authentic physical control labels and the existing screen interface; remove only promotional writing superimposed on the photograph. Conservatively reconstruct areas obscured by overlays using adjacent materials, without inventing new equipment. Do not add new lettering, logos, decorations, white padding or a new background. Produce one realistic, clean, high-definition photograph with the subject fully visible.';


    public const GEOMETRY_PROMPT = 'HIGHEST PRIORITY: the supplied radio and its display are an immutable geometric reference. Preserve the exact ORIGINAL width-to-height ratio of the entire radio, the active display, bezel, fascia and every control. Never stretch, squash, widen, narrow, lengthen, shorten, warp or redesign the product on either axis. Resize the whole product with ONE uniform scale factor only, identical horizontally and vertically. Keep the original camera viewpoint, orientation and perspective; do not rotate the product or introduce a new viewing angle. A square OUTPUT CANVAS does NOT mean a square radio: a wide radio must remain equally wide relative to its height. Fit the complete unchanged product inside the frame and extend only the surrounding background to fill the square. Never fill the square by reshaping the screen, enlarging its height, reducing its width or cropping the product. Preserve all edges, screen corners, bezel thickness and relative button positions. If composition or styling conflicts with original product geometry, original product geometry takes priority.';

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
        $path = $disk->path((in_array($mode, ['white', 'clean'], true) || $points || $mask) ? $image['path'] : ($original['uncropped_path'] ?? $original['path']));
        $prompt = match ($mode) {
            'white' => self::WHITE_PROMPT, 'clean' => self::CLEAN_PROMPT, default => self::PROMPT
        };
        $prompt = self::GEOMETRY_PROMPT."\n\n".$prompt;
        $info = is_file($path) && ! is_link($path) ? @getimagesize($path) : false;
        if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true) || filesize($path) > 8 * 1024 * 1024) {
            throw new RuntimeException('Immagine originale non disponibile o non valida.');
        }
        if ($mask) {
            $mi = @getimagesize($mask->getRealPath());
            if (!$mi || $mi['mime'] !== 'image/png' || $mi[0] !== $info[0] || $mi[1] !== $info[1] || $info[0] * $info[1] > 16000000) throw new RuntimeException('Maschera automatica non valida.');
        }
        if ($mode === 'scene') {
            $prompt = 'Create a photorealistic premium studio product-photography SET ONLY, with no product. Canary Islands desert setting: sand dunes, full sun, clear blue sky, editorial style. One broad natural stone platform in the foreground, with a flat horizontal top surface facing the camera. Square 1024x1024 full-bleed image. CRITICAL COMPOSITION: the platform spans almost the full image width, its flat top supports an object at horizontal center x=512, contact line y=760 pixels (74 percent from the top); stone top surface extends behind and in front of that contact line, with the front edge below y=800. Keep the central area above the platform clear for later compositing of the real product. Realistic warm stone texture and daylight. No radio, screen, dashboard, vehicle, accessories, other props, people, writing, lettering, logos or watermarks anywhere. Do not generate the product or its silhouette.';
            if ($points) {
                foreach ($points as $point) {
                    if (!array_is_list($point) || count($point) !== 2 || $point[0] < 0 || $point[1] < 0 || $point[0] >= $info[0] || $point[1] >= $info[1]) throw new RuntimeException('Contorno non valido.');
                }
            }
        }
        $stream = $mode === 'scene' ? null : fopen($path, 'rb');
        set_time_limit(300);
        try {
            $request = Http::withToken(config('services.openai.api_key'))->acceptJson()->connectTimeout(15)->timeout(240);
            if ($mode !== 'scene') $request->attach('image', $stream, basename($path), ['Content-Type' => $info['mime']]);
            $data = $request->post('https://api.openai.com/v1/images/'.($mode === 'scene' ? 'generations' : 'edits'), [
                'model' => config('services.openai.image_model', 'gpt-image-1-mini'), 'prompt' => $prompt,
                'size' => '1024x1024', 'quality' => 'high', 'output_format' => 'png', 'n' => 1,
            ])->throw()->json();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $encoded = data_get($data, 'data.0.b64_json');
        $bytes = is_string($encoded) && strlen($encoded) <= 24 * 1024 * 1024 ? base64_decode($encoded, true) : false;
        $result = $bytes && strlen($bytes) <= 16 * 1024 * 1024 ? @getimagesizefromstring($bytes) : false;
        if (! $result || $result['mime'] !== 'image/png' || $result[0] !== 1024 || $result[1] !== 1024) {
            throw new RuntimeException('Il servizio AI non ha restituito una foto quadrata valida.');
        }
        if ($mode === 'scene') $bytes = app(AutogenesiSceneComposite::class)->compose($path, $bytes, $points, $mask?->getRealPath());
        $target = 'autogenesi/products/'.$product->source_id.'/ai-'.Str::uuid().'.png';
        if (! $disk->put($target, $bytes)) {
            throw new RuntimeException('Salvataggio immagine AI non riuscito.');
        }
        try {
            DB::transaction(function () use ($product, $id, $original, $target, $bytes, $prompt, $mode) {
                $current = AutogenesiProduct::lockForUpdate()->findOrFail($product->id);
                $images = $current->images;
                $index = array_search($id, array_column($images, 'sha256'), true);
                if ($index === false) {
                    throw new RuntimeException('La foto è cambiata durante la modifica.');
                }
                $images[$index] = array_replace($images[$index], ['path' => $target, 'width' => 1024, 'height' => 1024,
                    'sha256' => hash('sha256', $bytes), 'original' => $original, 'ai_edited' => true,
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

    public function restore(AutogenesiProduct $product, string $id): void
    {
        $path = DB::transaction(function () use ($product, $id) {
            $current = AutogenesiProduct::lockForUpdate()->findOrFail($product->id);
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
