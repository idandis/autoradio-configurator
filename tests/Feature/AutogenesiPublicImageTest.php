<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutogenesiPublicImageTest extends TestCase
{
    public function test_catalog_photo_is_public_without_symlink_login_or_draft_record(): void
    {
        Storage::fake('public');
        $image = UploadedFile::fake()->image('photo.png', 300, 300);
        $bytes = file_get_contents($image->getRealPath());
        $hash = hash('sha256', $bytes);
        Storage::disk('public')->put('autogenesi/catalog/123/'.$hash.'.png', $bytes);
        $url = '/storage/autogenesi/catalog/123/'.$hash.'.png';
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=86400, public');
        $this->assertSame($bytes, file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->get($url, ['If-None-Match' => '"'.$hash.'"'])->assertStatus(304);
        $this->head($url)->assertOk();
    }

    public function test_private_missing_invalid_or_modified_files_are_not_served(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $hash = str_repeat('a', 64);
        Storage::disk('local')->put('autogenesi/catalog/123/'.$hash.'.png', 'private');
        $this->get('/storage/autogenesi/catalog/123/'.$hash.'.png')->assertClientError();
        Storage::disk('public')->put('autogenesi/catalog/123/'.$hash.'.png', '<?php secret');
        $this->get('/storage/autogenesi/catalog/123/'.$hash.'.png')->assertClientError();
        $this->get('/storage/autogenesi/catalog/123/manifest.json')->assertClientError();
        $this->get('/storage/autogenesi/catalog/123/'.$hash.'.php')->assertClientError();
    }
}
