<?php

namespace Tests\Feature;

use App\Models\AutogenesiProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutogenesiMaskedCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        Storage::fake('local'); Storage::fake('public');
        config(['services.openai.api_key' => 'test', 'services.openai.image_model' => 'gpt-image-1-mini']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function png(int $w, int $h, array $rgb): string
    {
        $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
        ob_start(); imagepng($im); $bytes = ob_get_clean(); imagedestroy($im); return $bytes;
    }

    private function draft(): AutogenesiProduct
    {
        $bytes = $this->png(600, 300, [210, 30, 60]);
        $pattern = imagecreatefromstring($bytes);
        imagefilledrectangle($pattern, 110, 50, 110, 60, imagecolorallocate($pattern, 255, 255, 255));
        ob_start(); imagepng($pattern); $bytes = ob_get_clean(); imagedestroy($pattern);
        Storage::disk('local')->put('draft/source.png', $bytes);
        return AutogenesiProduct::create(['source_id' => '123', 'source_url' => 'https://example.com/product', 'status' => 'draft', 'handle' => 'ford-focus-123',
            'title' => 'Pantalla 10" 1DIN Ford Focus', 'body_html' => '<p>Radio</p>', 'brand' => 'Ford', 'model' => 'Focus', 'year_from' => 2001, 'year_to' => 2005,
            'metadata' => [], 'variants' => [['sku' => 'AE-123-1', 'label' => '4GB', 'supplier_price' => '100', 'selling_price' => '210']],
            'images' => [['path' => 'draft/source.png', 'sha256' => hash('sha256', $bytes), 'width' => 600, 'height' => 300, 'role' => 'prodotto', 'source_url' => null]]]);
    }

    private function mask(bool $empty = false): UploadedFile
    {
        $im = imagecreatetruecolor(600, 300);
        if (!$empty) imagefilledrectangle($im, 100, 50, 120, 60, imagecolorallocate($im, 255, 255, 255));
        ob_start(); imagepng($im); $bytes = ob_get_clean(); imagedestroy($im);
        return UploadedFile::fake()->createWithContent('mask.png', $bytes);
    }

    public function test_blur_changes_only_selected_pixels_without_any_ai_call(): void
    {
        $p = $this->draft(); $before = $p->images;
        config(['services.openai.api_key' => null]);
        $this->post('/autogenesi/'.$p->id.'/images/0/cleanup-text', ['image_id' => $before[0]['sha256'], 'mask' => $this->mask()])->assertSessionHasNoErrors();
        $image = $p->fresh()->images[0];
        $this->assertSame([600, 300], [$image['width'], $image['height']]);
        $this->assertTrue($image['masked_blur']);
        $this->assertSame(12, $image['blur_strength']);
        $this->assertSame($before[0], $image['original']);
        $out = imagecreatefromstring(Storage::disk('local')->get($image['path']));
        $original = imagecreatefromstring(Storage::disk('local')->get($before[0]['path']));
        $unexpected = 0; $edited = 0;
        for ($y = 0; $y < 300; $y++) for ($x = 0; $x < 600; $x++) {
            $selected = $x >= 100 && $x <= 120 && $y >= 50 && $y <= 60;
            if (!$selected && imagecolorat($out, $x, $y) !== imagecolorat($original, $x, $y)) $unexpected++;
            if ($selected && imagecolorat($out, $x, $y) !== imagecolorat($original, $x, $y)) $edited++;
        }
        $this->assertSame(0, $unexpected);
        $this->assertGreaterThan(0, $edited);
        $this->assertNotSame(0xffffff, imagecolorat($out, 110, 55));
        Http::assertNothingSent();
        $this->post('/autogenesi/'.$p->id.'/images/0/restore', ['image_id' => $image['sha256']])->assertSessionHasNoErrors();
        $this->assertSame($before, $p->fresh()->images);
    }

    public function test_local_enhancement_sharpens_without_resize_or_ai_and_allows_no_text_selection(): void
    {
        $p = $this->draft(); $before = $p->images;
        config(['services.openai.api_key' => null]);
        $this->post('/autogenesi/'.$p->id.'/images/0/clean', ['image_id' => $before[0]['sha256'], 'mask' => $this->mask(true)])->assertSessionHasNoErrors();
        $image = $p->fresh()->images[0];
        $this->assertTrue($image['locally_enhanced']);
        $this->assertFalse($image['masked_blur']);
        $this->assertSame([600, 300], [$image['width'], $image['height']]);
        $out = imagecreatefromstring(Storage::disk('local')->get($image['path']));
        $pixel = imagecolorsforindex($out, imagecolorat($out, 109, 55));
        $this->assertLessThan(210, $pixel['red']);
        $this->assertSame(0xd21e3c, imagecolorat($out, 10, 10));
        $this->assertSame($before[0], $image['original']);
        Http::assertNothingSent();
    }

    public function test_ai_cleanup_uses_original_dashboard_as_reference_and_keeps_restore_available(): void
    {
        $p = $this->draft(); $before = $p->images;
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(1024, 1024, [200, 200, 200]))]]])]);
        $this->post('/autogenesi/'.$p->id.'/images/0/clean-ai', ['image_id' => $before[0]['sha256'], 'mask' => $this->mask()])->assertSessionHasNoErrors();
        $image = $p->fresh()->images[0];
        $this->assertSame('dashboard_clean', $image['ai_edit_mode']);
        $this->assertStringContainsString('Inpaint ONLY the transparent areas', $image['ai_prompt']);
        $this->assertSame([1024, 1024], [$image['width'], $image['height']]);
        $this->assertSame($before[0], $image['original']);
        $out = imagecreatefromstring(Storage::disk('local')->get($image['path']));
        $this->assertSame(0xd21e3c, imagecolorat($out, 50, 300) & 0xffffff);
        $this->assertSame(0xc8c8c8, imagecolorat($out, 187, 350) & 0xffffff);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/images/edits'));
        $this->post('/autogenesi/'.$p->id.'/images/0/restore', ['image_id' => $image['sha256']])->assertSessionHasNoErrors();
        $this->assertSame($before, $p->fresh()->images);
    }

    public function test_ai_cleanup_requires_a_selected_area(): void
    {
        $p = $this->draft();
        $this->post('/autogenesi/'.$p->id.'/images/0/clean-ai', ['image_id' => $p->images[0]['sha256']])->assertSessionHasErrors('mask');
        $this->post('/autogenesi/'.$p->id.'/images/0/clean-ai', ['image_id' => $p->images[0]['sha256'], 'mask' => $this->mask(true)])->assertSessionHasErrors('image_ai');
        $this->assertSame($p->images, $p->fresh()->images);
        Http::assertNothingSent();
    }

    public function test_ai_cleanup_uses_the_visible_crop_and_preserves_pixels_outside_selection(): void
    {
        $p = $this->draft(); $before = $p->images[0];
        app(\App\Services\AutogenesiImageCrop::class)->crop($p, $before['sha256'], 50, 20, 200, 150);
        $cropped = $p->fresh()->images[0];
        $mask = imagecreatetruecolor(200, 150);
        imagefilledrectangle($mask, 55, 25, 65, 35, imagecolorallocate($mask, 255, 255, 255));
        ob_start(); imagepng($mask); $maskBytes = ob_get_clean(); imagedestroy($mask);
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png(1024, 1024, [200, 200, 200]))]]])]);
        $this->post('/autogenesi/'.$p->id.'/images/0/clean-ai', [
            'image_id' => $cropped['sha256'], 'mask' => UploadedFile::fake()->createWithContent('mask.png', $maskBytes),
        ])->assertSessionHasNoErrors();
        $image = $p->fresh()->images[0];
        $out = imagecreatefromstring(Storage::disk('local')->get($image['path']));
        $this->assertSame(0xc8c8c8, imagecolorat($out, 307, 282) & 0xffffff);
        $this->assertNotSame(0xc8c8c8, imagecolorat($out, 50, 200) & 0xffffff);
        $this->assertSame($before, $image['original']);
        $this->post('/autogenesi/'.$p->id.'/images/0/restore', ['image_id' => $image['sha256']])->assertSessionHasNoErrors();
        $this->assertSame($before, $p->fresh()->images[0]);
    }

    public function test_empty_mask_is_rejected_without_api_call_or_image_changes(): void
    {
        $p = $this->draft();
        $this->post('/autogenesi/'.$p->id.'/images/0/cleanup-text', ['image_id' => $p->images[0]['sha256'], 'mask' => $this->mask(true)])
            ->assertSessionHasErrors('mask');
        $this->assertSame($p->images, $p->fresh()->images);
        Http::assertNothingSent();
    }

    public function test_wrong_mask_dimensions_preserve_current_photo(): void
    {
        $p = $this->draft();
        $wrong = UploadedFile::fake()->image('mask.png', 100, 100);
        $this->post('/autogenesi/'.$p->id.'/images/0/cleanup-text', ['image_id' => $p->images[0]['sha256'], 'mask' => $wrong])
            ->assertSessionHasErrors('mask');
        $this->assertSame($p->images, $p->fresh()->images);
        Http::assertNothingSent();
    }

    public function test_requires_admin_and_local_environment(): void
    {
        $p = $this->draft();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->post('/autogenesi/'.$p->id.'/images/0/cleanup-text')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true])); $this->app['env'] = 'production';
        $this->post('/autogenesi/'.$p->id.'/images/0/cleanup-text')->assertForbidden();
        Http::assertNothingSent();
    }
}
