<?php

namespace Tests\Feature;

use App\Services\AutogenesiExtraImages;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutogenesiExtraImagesTest extends TestCase
{
    public function test_imports_description_images_with_original_proportions_and_orders_dashboard_last(): void
    {
        Storage::fake('local');
        $dir = sys_get_temp_dir().'/autogenesi-extra-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $photo = imagecreatetruecolor(800, 400);
        imagefill($photo, 0, 0, imagecolorallocate($photo, 220, 30, 30));
        imagefilledrectangle($photo, 0, 0, 99, 399, imagecolorallocate($photo, 30, 220, 30));
        imagepng($photo, $dir.'/description-1.bin');
        copy($dir.'/description-1.bin', $dir.'/description-2.bin');
        $source = fn ($i) => ['file' => 'description-'.$i.'.bin', 'source_url' => 'https://ae-pic-a1.aliexpress-media.com/kf/photo-'.$i.'.jpg'];
        $data = ['source_id' => '123', 'description' => ['images' => [$source(1), $source(2)]]];
        try {
            $images = app(AutogenesiExtraImages::class)->append([['role' => 'da_verificare', 'source_url' => 'https://ae-pic-a1.aliexpress-media.com/kf/main.jpg']], $data,
                ['accessories_image' => 'description-1.bin', 'before_after_image' => 'description-2.bin'], $dir);
            $this->assertSame(['da_verificare', 'accessori', 'cruscotto_prima_dopo'], array_column($images, 'role'));
            $disk = Storage::disk('local');
            $photo = imagecreatefromstring($disk->get($images[1]['path']));
            $this->assertSame([800, 400], [imagesx($photo), imagesy($photo)]);
            $left = imagecolorsforindex($photo, imagecolorat($photo, 5, 200));
            $right = imagecolorsforindex($photo, imagecolorat($photo, 795, 200));
            $this->assertGreaterThan(180, $left['green']);
            $this->assertGreaterThan(180, $right['red']);
            $this->assertNull($images[1]['uncropped_path']);
            $this->assertSame(file_get_contents($dir.'/description-1.bin'), $disk->get($images[1]['path']));
            $again = app(AutogenesiExtraImages::class)->append($images, $data, ['accessories_image' => 'description-1.bin'], $dir);
            $this->assertCount(3, $again);
        } finally {
            unlink($dir.'/description-1.bin');
            unlink($dir.'/description-2.bin');
            rmdir($dir);
        }
    }

    public function test_rejects_filenames_that_do_not_belong_to_captured_sources(): void
    {
        Storage::fake('local');
        $this->expectException(\RuntimeException::class);
        app(AutogenesiExtraImages::class)->append([], ['source_id' => '123'], ['accessories_image' => '../secret'], '/tmp');
    }
}
