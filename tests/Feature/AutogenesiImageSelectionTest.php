<?php

namespace Tests\Feature;

use App\Services\AutogenesiExtraImages;
use App\Services\AutogenesiImageSelection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutogenesiImageSelectionTest extends TestCase
{
    public function test_curates_three_product_photos_accessories_and_before_after_only(): void
    {
        $images = []; $sources = [];
        foreach (range(0, 8) as $i) {
            $role = $i === 7 ? 'accessori' : ($i === 8 ? 'cruscotto_prima_dopo' : 'da_verificare');
            $sources[] = ['file' => 'image-'.$i.'.bin', 'source_url' => 'https://example.com/'.$i];
            $images[] = ['sha256' => (string) $i, 'role' => $role, 'source_url' => 'https://example.com/'.$i];
        }
        $selected = app(AutogenesiExtraImages::class)->curate($images, ['images' => $sources], ['product_images' => ['image-2.bin', 'image-3.bin', 'image-4.bin']]);
        $this->assertSame(['2', '3', '4', '7', '8'], array_column($selected, 'sha256'));
        $this->assertSame(['prodotto', 'prodotto', 'prodotto', 'accessori', 'cruscotto_prima_dopo'], array_column($selected, 'role'));
    }

    public function test_visual_selection_keeps_variant_photos_and_excludes_unselected_ads(): void
    {
        config(['services.openai.api_key' => 'test', 'services.openai.text_model' => 'gpt-5-mini']);
        $dir = sys_get_temp_dir().'/image-selection-'.bin2hex(random_bytes(6)); File::ensureDirectoryExists($dir);
        $images = [];
        foreach (range(0, 4) as $i) {
            $file = 'photo-'.$i.'.bin';
            $photo = UploadedFile::fake()->image('photo.png', 600, 400);
            copy($photo->getRealPath(), $dir.'/'.$file);
            $images[] = ['file' => $file, 'source_url' => 'https://ae01.alicdn.com/'.$i.'.jpg'];
        }
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
            'product_images' => ['photo-0.bin', 'photo-1.bin'], 'accessories_image' => 'photo-2.bin', 'before_after_image' => 'photo-3.bin',
        ])]]]]])]);
        try {
            $result = app(AutogenesiImageSelection::class)->select($images, $dir, ['brand' => 'FIAT', 'model' => 'Panda']);
            $this->assertSame(['photo-0.bin', 'photo-1.bin', 'photo-2.bin', 'photo-3.bin'], array_column($result, 'file'));
            $this->assertSame(['prodotto', 'prodotto', 'accessori', 'cruscotto_prima_dopo'], array_column($result, 'role'));
            Http::assertSent(fn ($r) => str_contains($r['instructions'], 'variant images') && count(array_filter($r['input'][0]['content'], fn ($c) => $c['type'] === 'input_image')) === 5);
        } finally { File::deleteDirectory($dir); }
    }
}
