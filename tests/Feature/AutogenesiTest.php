<?php

namespace Tests\Feature;

use App\Models\AutogenesiProduct;
use App\Models\User;
use App\Services\AutogenesiDescription;
use App\Services\AutogenesiExport;
use App\Services\AutogenesiImport;
use App\Services\AutogenesiSearch;
use App\Services\AutogenesiSquareImage;
use App\Services\AutogenesiTemplateExport;
use App\Services\ConfiguratorCsvImporter;
use App\Services\WhatsAppCatalogSearch;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Tests\TestCase;

class AutogenesiTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        Storage::fake('local');
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->mock(AutogenesiDescription::class)->shouldReceive('generate')->andReturn([
            'title' => 'Autoradio 9″ 1DIN para Hyundai Kona 2017-2020', 'body_html' => '<p>Tu música te acompaña.</p><h3>Pantalla</h3><ul><li>Pantalla de 9 pulgadas.</li></ul>',
            'notes' => ['Compatibilidad del equipo original por verificar.'], 'model' => 'test', 'rules_hash' => 'test', 'generated_at' => now()->toIso8601String(),
        ]);
        $this->dir = sys_get_temp_dir().'/autogenesi-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->dir);
        $images = [];
        foreach (range(1, 3) as $i) {
            $file = UploadedFile::fake()->image('photo.png', 300 + $i, 300 + $i);
            file_put_contents($this->dir.'/image-'.$i.'.bin', file_get_contents($file->getRealPath()));
            $images[] = ['file' => 'image-'.$i.'.bin', 'source_url' => 'https://ae-pic-a1.aliexpress-media.com/kf/photo-'.$i.'.jpg'];
        }
        file_put_contents($this->dir.'/manifest.json', json_encode(['source_id' => '1005009132362965',
            'source_url' => 'https://es.aliexpress.com/item/1005009132362965.html', 'source_title' => 'Radio Hyundai Kona',
            'variants' => [['label' => 'Android 14 64G 4G', 'supplier_price' => '343.69', 'currency' => 'EUR']],
            'images' => $images, 'status' => 'active', 'description' => ['text' => 'Pantalla de 9 pulgadas.', 'specifications' => [['name' => 'DIN', 'value' => 'Un DIN']], 'images' => [], 'complete' => true]]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function import(): AutogenesiProduct
    {
        return app(AutogenesiImport::class)->import($this->dir.'/manifest.json', ['brand' => 'HYUNDAI', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020]);
    }

    public function test_import_is_private_draft_with_three_images_and_no_public_catalog_entry(): void
    {
        $p = $this->import();
        $this->assertSame('draft', $p->status);
        $this->assertCount(3, $p->images);
        $this->assertSame('343.69', $p->variants[0]['supplier_price']);
        $this->assertSame('684', $p->variants[0]['selling_price']);
        $this->assertDatabaseCount('configurator_products', 0);
        $this->assertDatabaseCount('configurator_variants', 0);
        $this->assertSame(0, app(WhatsAppCatalogSearch::class)->search(['Hyundai Kona 2019'])['matching_specific_screens']);
        foreach ($p->images as $image) {
            Storage::disk('local')->assertExists($image['path']);
        }
        $this->assertFalse($p->metadata['compatibility_verified']);
        Http::assertNothingSent();
    }

    public function test_duplicate_import_preserves_manual_changes(): void
    {
        $p = $this->import();
        $p->update(['title' => 'Titolo corretto manualmente']);
        $next = $this->import();
        $this->assertSame($p->id, $next->id);
        $this->assertSame('Titolo corretto manualmente', $next->title);
        $this->assertDatabaseCount('autogenesi_products', 1);
        $this->assertCount(3, Storage::disk('local')->allFiles('autogenesi/products'));
    }

    public function test_draft_page_and_images_require_admin(): void
    {
        $p = $this->import();
        $this->get('/autogenesi')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/autogenesi')->assertForbidden();
        $this->get('/autogenesi/'.$p->id.'/images/0')->assertForbidden();
        $this->get('/autogenesi/'.$p->id.'/export')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/autogenesi')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Autogenesi')
            ->where('products.0.source_url', $p->source_url)->where('products.0.status', 'draft')->has('products.0.images', 3));
        $this->get('/autogenesi/'.$p->id.'/images/0')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_admin_can_edit_prices_and_remove_image_without_publishing(): void
    {
        $p = $this->import();
        $path = $p->images[1]['path'];
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->patch('/autogenesi/'.$p->id, ['title' => 'Radio verificata', 'body_html' => '<p>Descrizione</p><script>alert(1)</script>',
            'variants' => [['label' => 'Versione personalizzata', 'selling_price' => '499.01', 'supplier_price' => '1.00']], 'status' => 'active'])->assertRedirect();
        $p->refresh();
        $this->assertSame('500', $p->variants[0]['selling_price']);
        $this->assertSame('Versione personalizzata', $p->variants[0]['label']);
        $this->assertSame('Versione personalizzata', app(AutogenesiExport::class)->rows($p)[0]['Option1 Value']);
        $this->assertSame('343.69', $p->variants[0]['supplier_price']);
        $this->assertSame('draft', $p->status);
        $this->assertStringNotContainsString('<script', $p->body_html);
        $this->delete('/autogenesi/'.$p->id.'/images/1')->assertRedirect();
        $this->assertCount(2, $p->fresh()->images);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('configurator_products', 0);
    }

    public function test_removing_selected_image_preserves_other_images_after_indexes_shift(): void
    {
        $p = $this->import();
        $images = $p->images;
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->delete('/autogenesi/'.$p->id.'/images/0', ['image_id' => $images[0]['sha256']])->assertRedirect();
        $this->get('/autogenesi/'.$p->id.'/images/0?v='.$images[1]['sha256'])->assertOk();
        $this->delete('/autogenesi/'.$p->id.'/images/1', ['image_id' => $images[1]['sha256']])->assertRedirect();
        $this->assertSame([$images[2]['sha256']], array_column($p->fresh()->images, 'sha256'));
        Storage::disk('local')->assertMissing($images[1]['path']);
        Storage::disk('local')->assertExists($images[2]['path']);
        $this->delete('/autogenesi/'.$p->id.'/images/0', ['image_id' => $images[1]['sha256']])->assertNotFound();
    }

    public function test_admin_can_delete_product_and_import_it_again(): void
    {
        $p = $this->import();
        app(AutogenesiExport::class)->writeFiles($p);
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->delete('/autogenesi/'.$p->id)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->delete('/autogenesi/'.$p->id)->assertRedirect();
        $this->assertDatabaseCount('autogenesi_products', 0);
        foreach ($p->images as $image) {
            Storage::disk('local')->assertMissing($image['path']);
        }
        $this->assertEmpty(Storage::disk('local')->allFiles('autogenesi/exports/'.$p->id));
        $this->assertNotSame($p->id, $this->import()->id);
    }

    public function test_search_results_are_shown_without_creating_or_publishing_products(): void
    {
        $result = ['query' => 'radio Ford Escort 2019 2020', 'vehicle' => ['brand' => 'Ford', 'model' => 'Escort', 'year_from' => 2019, 'year_to' => 2020],
            'origin' => 'Todos', 'url' => 'https://es.aliexpress.com/w/wholesale-radio-Ford-Escort.html', 'candidates' => []];
        $this->mock(AutogenesiSearch::class)->makePartial()->shouldReceive('search')->once()->with('radio ford escort 2019.-2020', 'links')->andReturn($result);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/search', ['query' => 'radio ford escort 2019.-2020'])->assertRedirect();
        $this->get('/autogenesi')->assertInertia(fn (Assert $page) => $page->where('search', $result));
        $this->assertDatabaseCount('autogenesi_products', 0);
    }

    public function test_manual_query_sets_vehicle_and_browser_search_without_marketing_terms(): void
    {
        Process::fake(function ($process) {
            $args = $process->command;
            $this->assertSame('radio ford escort 2019 2020', $args[2]);
            $this->assertSame(['brand' => 'ford', 'model' => 'escort', 'year_from' => 2019, 'year_to' => 2020], json_decode($args[4], true));
            File::ensureDirectoryExists($args[3]);
            file_put_contents($args[3].'/results.json', json_encode(['origin' => 'EU', 'url' => 'https://es.aliexpress.com', 'candidates' => []]));

            return Process::result();
        });
        $result = app(AutogenesiSearch::class)->search('radio ford escort 2019.-2020');
        $this->assertSame(2019, $result['vehicle']['year_from']);
        $this->assertSame('EU', $result['origin']);
    }

    public function test_best_price_passes_mode_and_accepts_single_year_without_repeating_radio(): void
    {
        Process::fake(function ($process) {
            $args = $process->command;
            $this->assertSame('radio Hyundai KONA 2017', $args[2]);
            $this->assertSame('best_price', $args[5]);
            $this->assertSame(2017, json_decode($args[4], true)['year_to']);
            File::ensureDirectoryExists($args[3]);
            file_put_contents($args[3].'/results.json', json_encode(['origin' => 'EU', 'url' => 'https://es.aliexpress.com/?sortType=price_asc&shpf_co=EU', 'candidates' => [],
                'selected' => ['title' => 'Radio Hyundai Kona', 'source_url' => 'https://es.aliexpress.com/item/123.html', 'verified' => true], 'inspected_count' => 2]));

            return Process::result();
        });
        $result = app(AutogenesiSearch::class)->search('radio radio Hyundai KONA 2017', 'best_price');
        $this->assertTrue($result['selected']['verified']);
        $this->assertSame(2, $result['inspected_count']);
        $this->assertDatabaseCount('autogenesi_products', 0);
    }

    public function test_best_price_prepares_selection_without_importing_a_product(): void
    {
        $result = ['query' => 'radio Hyundai KONA 2017', 'mode' => 'best_price', 'origin' => 'EU', 'url' => 'https://es.aliexpress.com/?sortType=price_asc',
            'vehicle' => ['brand' => 'Hyundai', 'model' => 'KONA', 'year_from' => 2017, 'year_to' => 2017], 'candidates' => [],
            'selected' => ['title' => 'Radio Hyundai KONA 2017-2020', 'source_url' => 'https://es.aliexpress.com/item/123.html', 'verified' => true, 'price_text' => '199 €']];
        $this->mock(AutogenesiSearch::class)->makePartial()->shouldReceive('search')->once()->with('radio Hyundai KONA 2017', 'best_price')->andReturn($result);
        $this->mock(AutogenesiImport::class)->shouldNotReceive('capture');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/search', ['query' => 'radio Hyundai KONA 2017', 'mode' => 'best_price'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/autogenesi')->assertInertia(fn (Assert $page) => $page->where('search.mode', 'best_price')->where('search.selected.source_url', $result['selected']['source_url']));
        $this->assertDatabaseCount('autogenesi_products', 0);
        Http::assertNothingSent();
    }

    public function test_regeneration_preserves_selected_photos_and_manual_prices(): void
    {
        $p = $this->import();
        $variants = $p->variants;
        $variants[0]['selling_price'] = '499.00';
        $images = [$p->images[1]];
        $p->update(['title' => 'Vecchia descrizione', 'variants' => $variants, 'images' => $images]);
        Process::fake(function ($process) {
            $dir = $process->command[3];
            File::ensureDirectoryExists($dir);
            copy($this->dir.'/manifest.json', $dir.'/manifest.json');

            return Process::result();
        });
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/description')->assertRedirect()->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame('499.00', $p->variants[0]['selling_price']);
        $this->assertSame($images, $p->images);
        $this->assertSame('Vecchia descrizione', $p->metadata['previous_copy']['title']);
        $this->assertSame('draft', $p->status);
    }

    public function test_failed_regeneration_preserves_the_existing_draft(): void
    {
        $p = $this->import();
        $before = $p->only(['title', 'body_html', 'variants', 'images', 'metadata']);
        $this->mock(AutogenesiImport::class)->shouldReceive('regenerate')->once()->andThrow(new RuntimeException('Lettura non disponibile'));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/description')->assertRedirect()->assertSessionHasErrors('description');
        $this->assertSame($before, $p->fresh()->only(array_keys($before)));
    }

    private function fakeImageEdit(): string
    {
        config(['services.openai.api_key' => 'test-key', 'services.openai.image_model' => 'gpt-image-1-mini']);
        $file = UploadedFile::fake()->image('edited.png', 1024, 1024);
        $bytes = file_get_contents($file->getRealPath());
        Http::fake(['https://api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($bytes)]]])]);

        return $bytes;
    }

    public function test_ai_edits_selected_image_and_restores_original_without_exposing_it(): void
    {
        $p = $this->import();
        $before = $p->images;
        $this->fakeImageEdit();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/0/ai', ['image_id' => $before[1]['sha256']])->assertRedirect()->assertSessionHasNoErrors();
        $p->refresh();
        $edited = $p->images[1];
        $this->assertSame($before[0], $p->images[0]);
        $this->assertSame($before[2], $p->images[2]);
        $this->assertTrue($edited['ai_edited']);
        $this->assertSame([1024, 1024], [$edited['width'], $edited['height']]);
        $this->assertSame($before[1], $edited['original']);
        Storage::disk('local')->assertExists([$edited['path'], $before[1]['path']]);
        $this->assertNotContains($before[1]['source_url'], array_column(app(AutogenesiExport::class)->rows($p), 'Image Src'));
        $this->get('/autogenesi/'.$p->id.'/images/0?v='.$edited['sha256'].'&download=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(function ($r) {
            $form = collect($r->data())->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']])->all();

            return $r->isMultipart() && $r->hasFile('image') && $form['size'] === '1024x1024' && $form['model'] === 'gpt-image-1-mini'
                && str_contains($form['prompt'], 'Canary Islands') && str_contains($form['prompt'], 'stone platform')
                && str_contains($form['prompt'], 'clear blue sky') && str_contains($form['prompt'], 'No writing')
                && str_contains($form['prompt'], 'width-to-height ratio') && str_contains($form['prompt'], 'ONE uniform scale factor')
                && str_contains($form['prompt'], 'square OUTPUT CANVAS does NOT mean a square radio');
        });
        $this->post('/autogenesi/'.$p->id.'/images/0/restore', ['image_id' => $edited['sha256']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $p->fresh()->images);
        Storage::disk('local')->assertMissing($edited['path']);
        $this->assertContains(config('autogenesi.media_base_url').'/autogenesi/catalog/'.$p->source_id.'/'.$before[1]['sha256'].'.png', array_column(app(AutogenesiExport::class)->rows($p->fresh()), 'Image Src'));
        $this->assertSame('draft', $p->fresh()->status);
    }

    public function test_variant_deletion_targets_sku_and_updates_exports_without_changing_other_variants(): void
    {
        $p = $this->import();
        $first = $p->variants[0];
        $variants = [$first, array_replace($first, ['sku' => 'AE-second', 'label' => 'Seconda']), array_replace($first, ['sku' => 'AE-third', 'label' => 'Terza'])];
        $p->update(['variants' => $variants]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->delete('/autogenesi/'.$p->id.'/variants', ['sku' => 'AE-second'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$variants[0], $variants[2]], $p->fresh()->variants);
        $this->assertSame('draft', $p->fresh()->status);
        $paths = Storage::disk('local')->allFiles('autogenesi/exports/'.$p->id);
        $this->assertCount(1, $paths);
        $sheet = IOFactory::load(Storage::disk('local')->path($paths[0]))->getSheetByName('Products')->toArray();
        $this->assertStringNotContainsString('AE-second', json_encode($sheet));
        $this->assertStringContainsString('AE-third', json_encode($sheet));
        $this->assertCount(3, $p->fresh()->images);
    }

    public function test_last_variant_cannot_be_removed_and_unknown_sku_does_not_remove_another_row(): void
    {
        $p = $this->import();
        $before = $p->variants;
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->delete('/autogenesi/'.$p->id.'/variants', ['sku' => 'missing'])->assertNotFound();
        $this->delete('/autogenesi/'.$p->id.'/variants', ['sku' => $before[0]['sku']])->assertRedirect()->assertSessionHasErrors('variant');
        $this->assertSame($before, $p->fresh()->variants);
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->delete('/autogenesi/'.$p->id.'/variants', ['sku' => $before[0]['sku']])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->app['env'] = 'production';
        $this->delete('/autogenesi/'.$p->id.'/variants', ['sku' => $before[0]['sku']])->assertForbidden();
    }

    public function test_common_image_formats_upload_with_original_proportions_and_duplicates_are_ignored(): void
    {
        $p = $this->import();
        $before = $p->images;
        $files = [];
        foreach (['jpg', 'png', 'webp', 'gif', 'bmp', 'avif'] as $i => $format) {
            $image = imagecreatetruecolor(400, 300);
            imagefill($image, 0, 0, imagecolorallocate($image, 40 + $i * 25, 60, 170));
            ob_start();
            match ($format) {
                'jpg' => imagejpeg($image), 'png' => imagepng($image), 'webp' => imagewebp($image),
                'gif' => imagegif($image), 'bmp' => imagebmp($image), 'avif' => imageavif($image),
            };
            $files[] = UploadedFile::fake()->createWithContent('photo.'.$format, ob_get_clean());
        }
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images', ['photos' => $files, 'role' => 'accessori'])->assertRedirect()->assertSessionHasNoErrors();
        $images = $p->fresh()->images;
        $this->assertCount(9, $images);
        $this->assertSame($before, array_slice($images, 0, 3));
        foreach (array_slice($images, 3) as $image) {
            $this->assertSame([400, 300], [$image['width'], $image['height']]);
            $this->assertTrue($image['uploaded']);
            $this->assertNull($image['source_url']);
            $this->assertSame('accessori', $image['role']);
            Storage::disk('local')->assertExists($image['path']);
            $this->assertNull($image['uncropped_path']);
        }
        $this->post('/autogenesi/'.$p->id.'/images', ['photos' => [$files[1]], 'role' => 'accessori'])->assertSessionHasNoErrors();
        $this->assertCount(9, $p->fresh()->images);
        $this->assertSame('draft', $p->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('autogenesi/uploads'));
        Http::assertNothingSent();
    }

    public function test_uploaded_square_photo_has_prepared_aruba_url_and_dashboard_stays_last(): void
    {
        $p = $this->import();
        $before = $p->images;
        $before[2]['role'] = 'cruscotto_prima_dopo';
        $p->update(['images' => $before]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images', ['photos' => [UploadedFile::fake()->image('square.png', 450, 450)], 'role' => 'prodotto'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $images = $p->fresh()->images;
        $this->assertSame('cruscotto_prima_dopo', $images[3]['role']);
        $uploaded = collect($images)->firstWhere('uploaded', true);
        $this->assertFalse($uploaded['square_cropped']);
        $this->assertCount(4, array_filter(array_column(app(AutogenesiExport::class)->rows($p->fresh()), 'Image Src')));
        $this->get('/autogenesi')->assertInertia(fn (Assert $page) => $page->where('products.0.images.2.local_only', true));
    }

    public function test_invalid_upload_rolls_back_all_photos_and_rejects_vector_files(): void
    {
        $p = $this->import();
        $before = $p->images;
        $paths = Storage::disk('local')->allFiles('autogenesi/products');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images', ['photos' => [UploadedFile::fake()->image('valid.png', 500, 500),
            UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')], 'role' => 'prodotto'])
            ->assertRedirect()->assertSessionHasErrors('photos');
        $this->assertSame($before, $p->fresh()->images);
        $this->assertSame($paths, Storage::disk('local')->allFiles('autogenesi/products'));
        $this->assertSame([], Storage::disk('local')->allFiles('autogenesi/uploads'));
        Http::assertNothingSent();
    }

    public function test_photo_upload_requires_local_admin_and_enforces_file_size(): void
    {
        $p = $this->import();
        $data = ['photos' => [UploadedFile::fake()->image('photo.png', 400, 400)], 'role' => 'prodotto'];
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->post('/autogenesi/'.$p->id.'/images', $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->app['env'] = 'production';
        $this->post('/autogenesi/'.$p->id.'/images', $data)->assertForbidden();
        $this->app['env'] = 'local';
        $data['photos'] = [UploadedFile::fake()->image('large.png', 400, 400)->size(8193)];
        $this->post('/autogenesi/'.$p->id.'/images', $data)->assertRedirect()->assertSessionHasErrors('photos.0');
        $this->assertCount(3, $p->fresh()->images);
    }

    public function test_square_crop_uses_selected_photo_region_and_can_restore_original(): void
    {
        $p = $this->import();
        $before = $p->images;
        $source = imagecreatetruecolor(302, 302);
        imagefill($source, 0, 0, imagecolorallocate($source, 220, 20, 20));
        imagefilledrectangle($source, 151, 0, 301, 301, imagecolorallocate($source, 20, 20, 220));
        ob_start();
        imagepng($source);
        $bytes = ob_get_clean();
        Storage::disk('local')->put($before[1]['path'], $bytes);
        $before[1]['sha256'] = hash('sha256', $bytes);
        $p->update(['images' => $before]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/0/crop', ['image_id' => $before[1]['sha256'], 'x' => 160, 'y' => 40, 'size' => 100])
            ->assertRedirect()->assertSessionHasNoErrors();
        $images = $p->fresh()->images;
        $this->assertSame($before[0], $images[0]);
        $this->assertSame($before[2], $images[2]);
        $this->assertSame([100, 100], [$images[1]['width'], $images[1]['height']]);
        $this->assertSame($before[1], $images[1]['original']);
        $this->assertTrue($images[1]['manually_cropped']);
        $result = imagecreatefromstring(Storage::disk('local')->get($images[1]['path']));
        $pixel = imagecolorsforindex($result, imagecolorat($result, 50, 50));
        $this->assertGreaterThan(180, $pixel['blue']);
        $this->assertLessThan(50, $pixel['red']);
        $this->assertNotContains($before[1]['source_url'], array_column(app(AutogenesiExport::class)->rows($p->fresh()), 'Image Src'));
        $this->post('/autogenesi/'.$p->id.'/images/0/restore', ['image_id' => $images[1]['sha256']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $p->fresh()->images);
        Storage::disk('local')->assertMissing($images[1]['path']);
        Http::assertNothingSent();
    }

    public function test_crop_rejects_outside_selection_without_changing_image(): void
    {
        $p = $this->import();
        $before = $p->images;
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/0/crop', ['image_id' => $before[0]['sha256'], 'x' => 250, 'y' => 0, 'size' => 200])
            ->assertRedirect()->assertSessionHasErrors('image_crop');
        $this->assertSame($before, $p->fresh()->images);
        Http::assertNothingSent();
    }

    public function test_background_removal_uses_current_crop_and_keeps_original_and_other_photos(): void
    {
        $p = $this->import();
        $before = $p->images;
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/1/crop', ['image_id' => $before[1]['sha256'], 'x' => 10, 'y' => 10, 'size' => 200])->assertSessionHasNoErrors();
        $crop = $p->fresh()->images[1];
        $this->fakeImageEdit();
        $this->post('/autogenesi/'.$p->id.'/images/0/white', ['image_id' => $crop['sha256']])->assertRedirect()->assertSessionHasNoErrors();
        $images = $p->fresh()->images;
        $this->assertSame($before[0], $images[0]);
        $this->assertSame($before[2], $images[2]);
        $this->assertSame($before[1], $images[1]['original']);
        $this->assertSame('white', $images[1]['ai_edit_mode']);
        Http::assertSent(function ($r) use ($crop) {
            $form = collect($r->data())->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']])->all();

            return $r->hasFile('image', null, basename($crop['path'])) && str_contains($form['prompt'], '#FFFFFF')
                && str_contains($form['prompt'], 'black text') && str_contains($form['prompt'], 'every actual product and accessory')
                && ! str_contains($form['prompt'], 'Canary Islands');
        });
        Storage::disk('local')->assertMissing($crop['path']);
        $this->assertSame('draft', $p->fresh()->status);
    }

    public function test_cleanup_uses_current_photo_and_restores_original_without_changing_other_images(): void
    {
        $p = $this->import();
        $before = $p->images;
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/1/crop', ['image_id' => $before[1]['sha256'], 'x' => 10, 'y' => 10, 'size' => 200])->assertSessionHasNoErrors();
        $crop = $p->fresh()->images[1];
        $this->fakeImageEdit();
        $this->post('/autogenesi/'.$p->id.'/images/0/clean', ['image_id' => $crop['sha256']])->assertRedirect()->assertSessionHasNoErrors();
        $images = $p->fresh()->images;
        $this->assertSame($before[0], $images[0]);
        $this->assertSame($before[2], $images[2]);
        $this->assertSame('clean', $images[1]['ai_edit_mode']);
        $this->assertSame($before[1], $images[1]['original']);
        Http::assertSent(function ($r) use ($crop) {
            $form = collect($r->data())->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']])->all();

            return $r->hasFile('image', null, basename($crop['path'])) && str_contains($form['prompt'], 'advertising text')
                && str_contains($form['prompt'], 'arrows') && str_contains($form['prompt'], 'exact dashboard')
                && str_contains($form['prompt'], 'Improve definition') && ! str_contains($form['prompt'], 'Canary Islands')
                && ! str_contains($form['prompt'], '#FFFFFF');
        });
        $this->post('/autogenesi/'.$p->id.'/images/0/restore', ['image_id' => $images[1]['sha256']])->assertSessionHasNoErrors();
        $this->assertSame($before, $p->fresh()->images);
        Storage::disk('local')->assertMissing($images[1]['path']);
        $this->assertSame('draft', $p->fresh()->status);
    }

    public function test_image_edit_requires_admin_local_environment_and_valid_image_id(): void
    {
        $p = $this->import();
        $input = ['image_id' => $p->images[0]['sha256']];
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->post('/autogenesi/'.$p->id.'/images/0/ai', $input)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/0/ai', ['image_id' => str_repeat('f', 64)])->assertNotFound();
        $this->app['env'] = 'production';
        $this->post('/autogenesi/'.$p->id.'/images/0/ai', $input)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_invalid_ai_image_preserves_original_and_other_photos(): void
    {
        $p = $this->import();
        $before = $p->images;
        config(['services.openai.api_key' => 'test-key']);
        Http::fake(['https://api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode('<html>error</html>')]]])]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/0/ai', ['image_id' => $before[0]['sha256']])->assertRedirect()->assertSessionHasErrors('image_ai');
        $this->assertSame($before, $p->fresh()->images);
        $this->assertCount(3, Storage::disk('local')->allFiles('autogenesi/products'));
    }

    public function test_removing_ai_photo_cleans_original_backup_and_keeps_neighbours(): void
    {
        $p = $this->import();
        $before = $p->images;
        $this->fakeImageEdit();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/1/ai', ['image_id' => $before[1]['sha256']])->assertSessionHasNoErrors();
        $edited = $p->fresh()->images[1];
        $this->delete('/autogenesi/'.$p->id.'/images/1', ['image_id' => $edited['sha256']])->assertRedirect();
        Storage::disk('local')->assertMissing([$edited['path'], $before[1]['path']]);
        Storage::disk('local')->assertExists([$before[0]['path'], $before[2]['path']]);
        $this->assertSame([$before[0], $before[2]], $p->fresh()->images);
    }

    public function test_deleted_product_cleans_ai_and_original_images(): void
    {
        $p = $this->import();
        $this->fakeImageEdit();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/0/ai', ['image_id' => $p->images[0]['sha256']])->assertSessionHasNoErrors();
        $this->delete('/autogenesi/'.$p->id)->assertRedirect();
        $this->assertEmpty(Storage::disk('local')->allFiles('autogenesi/products'));
    }

    public function test_repeated_ai_edit_uses_original_and_cleans_previous_ai_version(): void
    {
        $p = $this->import();
        $original = $p->images[0];
        $this->fakeImageEdit();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/0/ai', ['image_id' => $original['sha256']])->assertSessionHasNoErrors();
        $first = $p->fresh()->images[0];
        $this->post('/autogenesi/'.$p->id.'/images/0/ai', ['image_id' => $first['sha256']])->assertSessionHasNoErrors();
        $latest = $p->fresh()->images[0];
        $this->assertSame($original, $latest['original']);
        Storage::disk('local')->assertMissing($first['path']);
        Storage::disk('local')->assertExists([$latest['path'], $original['path']]);
        $this->assertCount(4, Storage::disk('local')->allFiles('autogenesi/products'));
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->hasFile('image', null, basename($original['path'])));
        }
    }

    public function test_removed_photo_cannot_be_replaced_by_a_delayed_ai_result(): void
    {
        $p = $this->import();
        $before = $p->images;
        $bytes = $this->fakeImageEdit();
        Http::fake(['https://api.openai.com/v1/images/edits' => function () use ($p, $before, $bytes) {
            $p->update(['images' => [$before[0], $before[2]]]);
            Storage::disk('local')->delete($before[1]['path']);

            return Http::response(['data' => [['b64_json' => base64_encode($bytes)]]]);
        }]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/1/ai', ['image_id' => $before[1]['sha256']])->assertSessionHasErrors('image_ai');
        $this->assertSame([$before[0], $before[2]], $p->fresh()->images);
        $this->assertCount(2, Storage::disk('local')->allFiles('autogenesi/products'));
    }

    public function test_import_keeps_rectangular_photos_and_ai_uses_full_source(): void
    {
        $file = UploadedFile::fake()->image('wide.png', 600, 300);
        copy($file->getRealPath(), $this->dir.'/image-2.bin');
        $p = $this->import();
        $image = $p->images[1];
        $this->assertSame([600, 300], [$image['width'], $image['height']]);
        $this->assertFalse($image['square_cropped']);
        $this->assertNull($image['uncropped_path']);
        $original = getimagesize(Storage::disk('local')->path($image['path']));
        $this->assertSame([600, 300], [$original[0], $original[1]]);
        $this->assertContains(config('autogenesi.media_base_url').'/autogenesi/catalog/'.$p->source_id.'/'.$image['sha256'].'.png', array_column(app(AutogenesiExport::class)->rows($p), 'Image Src'));
        $this->fakeImageEdit();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/'.$p->id.'/images/1/ai', ['image_id' => $image['sha256']])->assertSessionHasNoErrors();
        Http::assertSent(fn ($r) => $r->hasFile('image', null, basename($image['path'])));
        $edited = $p->fresh()->images[1];
        $this->post('/autogenesi/'.$p->id.'/images/1/restore', ['image_id' => $edited['sha256']])->assertSessionHasNoErrors();
        $this->assertSame([600, 300], [$p->fresh()->images[1]['width'], $p->fresh()->images[1]['height']]);
        $this->delete('/autogenesi/'.$p->id.'/images/1', ['image_id' => $image['sha256']])->assertRedirect();
        Storage::disk('local')->assertMissing($image['path']);
    }

    public function test_existing_rectangular_photos_are_normalized_once(): void
    {
        $p = $this->import();
        $images = $p->images;
        $file = UploadedFile::fake()->image('legacy.png', 600, 300);
        Storage::disk('local')->put($images[1]['path'], file_get_contents($file->getRealPath()));
        $images[1]['width'] = 600;
        $p->update(['images' => $images]);
        $service = app(AutogenesiSquareImage::class);
        $this->assertSame(1, $service->normalizeProduct($p));
        $square = $p->fresh()->images;
        $this->assertSame($images[0], $square[0]);
        $this->assertSame($images[2], $square[2]);
        $this->assertSame($images[1]['path'], $square[1]['uncropped_path']);
        $this->assertSame([300, 300], [$square[1]['width'], $square[1]['height']]);
        $this->assertSame(0, $service->normalizeProduct($p->fresh()));
    }

    public function test_template_excel_preserves_exact_columns_metafields_prices_and_draft_state(): void
    {
        $p = $this->import();
        $e = app(AutogenesiExport::class);
        $rows = $e->rows($p);
        $this->assertSame('Active', $rows[0]['Status']);
        $this->assertArrayNotHasKey(AutogenesiTemplateExport::SOURCE, $rows[0]);
        $this->assertSame('2017-2020', $rows[0][AutogenesiTemplateExport::YEAR]);
        $this->assertSame('1DIN', $rows[0][AutogenesiTemplateExport::DIN]);
        $this->assertSame('1:Kona', $rows[0][AutogenesiTemplateExport::MODEL]);
        $this->assertSame('684', $rows[0]['Variant Price']);
        $this->assertSame('', $rows[0]['ID']);
        $this->assertSame('', $rows[0]['Variant ID']);
        $this->assertSame('', $rows[0]['Price / Italia']);
        $this->assertSame('', $rows[1]['Variant SKU']);
        $this->assertNotSame('', $rows[0]['Body HTML']);
        $this->assertSame('', $rows[1]['Body HTML']);
        $this->assertSame('', $rows[2]['Body HTML']);
        $this->assertSame('', $rows[1][AutogenesiTemplateExport::BRAND]);
        $this->assertArrayNotHasKey(AutogenesiTemplateExport::SOURCE, $rows[1]);
        $this->assertSame($p->title, $rows[1]['Title']);
        $this->assertSame($p->handle, $rows[1]['Handle']);
        $columns = json_decode(file_get_contents(resource_path('autogenesi/export-columns.json')), true);
        $this->assertSame($columns, array_slice(array_keys($rows[0]), 0, 21));
        $required = (new \ReflectionClass(ConfiguratorCsvImporter::class))->getConstant('REQUIRED_HEADERS');
        $this->assertEmpty(array_diff($required, array_keys($rows[0])));
        $e->xlsx($p, $this->dir.'/export.xlsx');
        $book = IOFactory::load($this->dir.'/export.xlsx');
        $this->assertSame(['Products'], $book->getSheetNames());
        $sheet = $book->getSheetByName('Products')->toArray();
        $this->assertSame($columns, array_slice($sheet[0], 0, 21));
        $this->assertContains('Active', $sheet[1]);
        $images = $p->images;
        $images[1]['role'] = 'cruscotto_da_verificare';
        $p->images = $images;
        $this->assertSame(config('autogenesi.media_base_url').'/autogenesi/catalog/'.$p->source_id.'/'.$images[1]['sha256'].'.png', $e->rows($p)[1]['Image Src']);
    }

    public function test_export_analysis_flags_private_images_without_using_their_old_supplier_urls(): void
    {
        config(['autogenesi.media_base_url' => '']);
        $p = $this->import();
        $images = $p->images;
        foreach ($images as &$image) {
            $image['ai_edited'] = true;
        }
        unset($image);
        $images[2]['role'] = 'cruscotto_prima_dopo';
        $p->update(['images' => $images]);
        $template = app(AutogenesiTemplateExport::class);
        $this->assertSame(['', '', ''], array_column($template->rows($p), 'Image Src'));
        $analysis = collect($template->analysis($p));
        $this->assertSame('Da completare', $analysis->firstWhere('Campo', 'Image Src')['Stato']);
        $this->assertSame('Da completare', $analysis->firstWhere('Campo', AutogenesiTemplateExport::DASHBOARD)['Stato']);
        $this->assertSame('Assegnato da Shopify', $analysis->firstWhere('Campo', 'Variant ID')['Stato']);
        $this->assertSame('Vuoto come da esempio', $analysis->firstWhere('Campo', 'Price / Italia')['Stato']);
        $this->assertCount(3, $analysis->filter(fn ($r) => str_starts_with($r['Campo'], 'Foto ') && $r['Stato'] === 'Da completare'));
    }

    public function test_only_template_workbook_is_downloaded_and_old_export_files_are_removed(): void
    {
        $p = $this->import();
        $dir = 'autogenesi/exports/'.$p->id.'/';
        foreach (['-shopify-draft.csv', '-catalogo.csv', '-catalogo.xlsx'] as $suffix) {
            Storage::disk('local')->put($dir.$p->handle.$suffix, 'old');
        }
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/autogenesi/'.$p->id.'/export')->assertDownload($p->handle.'-autogenesi.xlsx');
        $files = Storage::disk('local')->allFiles($dir);
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('-autogenesi.xlsx', $files[0]);
        $book = IOFactory::load(Storage::disk('local')->path($files[0]));
        $this->assertSame('Products', $book->getSheet(0)->getTitle());
        $this->assertSame(1, $book->getSheetCount());
        Http::assertNothingSent();
    }

    public function test_draft_template_excel_cannot_be_imported_into_public_catalog(): void
    {
        $p = $this->import();
        $file = $this->dir.'/catalog.xlsx';
        app(AutogenesiExport::class)->xlsx($p, $file);
        $book = IOFactory::load($file);
        $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search('Status', app(AutogenesiTemplateExport::class)->columns(), true) + 1);
        $book->getSheetByName('Products')->setCellValue($column.'2', 'Draft');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);
        $book->disconnectWorksheets();
        try {
            app(ConfiguratorCsvImporter::class)->import($file, false);
            $this->fail('Draft imported into public catalog');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('prodotti in bozza', $e->getMessage());
        }
        $this->assertDatabaseCount('configurator_products', 0);
        Http::assertNothingSent();
    }

    public function test_import_rolls_back_files_when_image_is_invalid(): void
    {
        file_put_contents($this->dir.'/image-3.bin', '<html>not an image</html>');
        try {
            $this->import();
            $this->fail('Invalid image accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Formato', $e->getMessage());
        }
        $this->assertDatabaseCount('autogenesi_products', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles('autogenesi/products'));
    }

    public function test_images_must_be_distinct(): void
    {
        copy($this->dir.'/image-1.bin', $this->dir.'/image-2.bin');
        copy($this->dir.'/image-1.bin', $this->dir.'/image-3.bin');
        $this->expectExceptionMessage('Servono almeno tre immagini distinte.');
        $this->import();
    }

    public function test_source_link_must_match_product_id(): void
    {
        $file = $this->dir.'/manifest.json';
        $data = json_decode(file_get_contents($file), true);
        $data['source_url'] = 'https://es.aliexpress.com/item/123.html';
        file_put_contents($file, json_encode($data));
        $this->expectExceptionMessage('ID prodotto e link AliExpress non corrispondono.');
        $this->import();
    }

    private function chromePackage(): array
    {
        $manifest = json_decode(file_get_contents($this->dir.'/manifest.json'), true);
        foreach ($manifest['images'] as &$image) {
            $image['bytes_base64'] = base64_encode(file_get_contents($this->dir.'/'.$image['file']));
        }
        unset($image);
        $manifest['description']['html'] = '<p>Pantalla de 9 pulgadas.</p>';

        return ['format' => 'autogenesi.chrome.v1', 'manifest' => $manifest];
    }

    public function test_chrome_package_creates_private_draft_and_exports_without_browser_or_supplier_requests(): void
    {
        Process::preventStrayProcesses();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $file = UploadedFile::fake()->createWithContent('autogenesi.json', json_encode($this->chromePackage()));
        $this->post('/autogenesi/import-chrome', ['package' => $file, 'brand' => 'HYUNDAI', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020])
            ->assertRedirect()->assertSessionHasNoErrors();
        $p = AutogenesiProduct::firstOrFail();
        $this->assertSame('draft', $p->status);
        $this->assertCount(3, $p->images);
        $this->assertSame('684', $p->variants[0]['selling_price']);
        $this->assertSame('https://es.aliexpress.com/item/1005009132362965.html', $p->source_url);
        $this->assertCount(1, Storage::disk('local')->allFiles('autogenesi/exports/'.$p->id));
        $this->assertDatabaseCount('configurator_products', 0);
        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_chrome_package_rejects_paths_outside_capture_and_cleans_up_partial_files(): void
    {
        $data = $this->chromePackage();
        $data['manifest']['images'][1]['file'] = '../outside.bin';
        $file = UploadedFile::fake()->createWithContent('autogenesi.json', json_encode($data));
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/autogenesi/import-chrome', ['package' => $file, 'brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020])
            ->assertRedirect()->assertSessionHasErrors('package');
        $this->assertDatabaseCount('autogenesi_products', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('autogenesi/captures'));
        Http::assertNothingSent();
    }

    public function test_generic_chrome_package_imports_public_supplier_images_without_network_requests(): void
    {
        Process::preventStrayProcesses();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $data = $this->chromePackage();
        $url = 'https://www.example.com/products/radio?variant=128';
        $id = \App\Services\AutogenesiSource::id($url);
        $data['manifest']['source_url'] = $url;
        $data['manifest']['source_id'] = $id;
        $data['manifest']['description']['specifications'] = [];
        foreach ($data['manifest']['images'] as $i => &$image) $image['source_url'] = 'https://cdn.shopify.com/radio-'.$i.'.jpg?width=1200';
        unset($image);
        $file = UploadedFile::fake()->createWithContent('generic.json', json_encode($data));
        $this->post('/autogenesi/import-chrome', ['package' => $file, 'brand' => 'HYUNDAI', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020])
            ->assertRedirect()->assertSessionHasNoErrors();
        $p = AutogenesiProduct::firstOrFail();
        $this->assertSame($url, $p->source_url);
        $this->assertSame('web', $p->metadata['source_platform']);
        $this->assertStringStartsWith('WEB-'.$id.'-', $p->variants[0]['sku']);
        $this->assertCount(3, $p->images);
        $this->assertStringContainsString('?width=1200', $p->images[0]['source_url']);
        $this->assertDatabaseCount('configurator_products', 0);
        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_generic_package_rejects_private_sources_and_mismatched_identity(): void
    {
        foreach (['https://127.0.0.1/product', 'https://www.example.com/product'] as $url) {
            $data = $this->chromePackage();
            $data['manifest']['source_url'] = $url;
            $file = UploadedFile::fake()->createWithContent('bad.json', json_encode($data));
            $this->actingAs(User::factory()->create(['is_admin' => true]));
            $this->post('/autogenesi/import-chrome', ['package' => $file, 'brand' => 'HYUNDAI', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020])
                ->assertRedirect()->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('autogenesi_products', 0);
        Http::assertNothingSent();
    }

    public function test_dashboard_flag_survives_ai_edit_and_restore_without_reusing_wrong_shopify_reference(): void
    {
        $p = $this->import();
        $id = $p->images[0]['sha256'];
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->patch('/autogenesi/'.$p->id.'/images/layout', ['dashboard_image_id' => $id, 'shopify_file_reference' => 'originale.jpg'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->fakeImageEdit();
        $this->post('/autogenesi/'.$p->id.'/images/0/ai', ['image_id' => $id])->assertRedirect()->assertSessionHasNoErrors();
        $edited = \App\Services\AutogenesiImageLayout::dashboard($p->fresh());
        $this->assertTrue($edited['dashboard_selected']);
        $this->assertNotSame($id, $edited['sha256']);
        $this->assertArrayNotHasKey('shopify_file_reference', $edited);
        $this->assertSame($edited['sha256'].'.png', app(AutogenesiTemplateExport::class)->rows($p->fresh())[0][AutogenesiTemplateExport::DASHBOARD]);
        $this->post('/autogenesi/'.$p->id.'/images/0/restore', ['image_id' => $edited['sha256']])->assertRedirect()->assertSessionHasNoErrors();
        $restored = \App\Services\AutogenesiImageLayout::dashboard($p->fresh());
        $this->assertSame($id, $restored['sha256']);
        $this->assertTrue($restored['dashboard_selected']);
        $this->assertSame('originale.jpg', $restored['shopify_file_reference']);
    }

    public function test_manual_chrome_import_accepts_years_after_2020(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $file = UploadedFile::fake()->createWithContent('autogenesi.json', json_encode($this->chromePackage()));
        $this->post('/autogenesi/import-chrome', ['package' => $file, 'brand' => 'FORD', 'model' => 'Puma', 'year_from' => 2019, 'year_to' => 2026])
            ->assertRedirect()->assertSessionHasNoErrors();
        $p = AutogenesiProduct::firstOrFail();
        $this->assertSame(2019, (int) $p->year_from);
        $this->assertSame(2026, (int) $p->year_to);
        $vehicle = app(AutogenesiSearch::class)->parse('radio Ford Puma 2021 2026')['vehicle'];
        $this->assertSame(2026, $vehicle['year_to']);
        $this->assertSame(1900, app(AutogenesiSearch::class)->parse('radio Ford Model T 1900 1910')['vehicle']['year_from']);
    }

    public function test_import_corrects_search_vehicle_from_original_description_compatibilities(): void
    {
        $manifest = json_decode(file_get_contents($this->dir.'/manifest.json'), true);
        $manifest['description']['text'] .= ' Compatible con Fiat 500X 2014-2020.';
        file_put_contents($this->dir.'/manifest.json', json_encode($manifest));
        $this->app->instance(AutogenesiDescription::class, new class extends AutogenesiDescription {
            public function generate(array $data, array $vehicle, string $dir): array
            {
                return ['title' => 'Radio 7 pulgadas 2DIN Fiat 500X 2014-2020', 'body_html' => '<p>Fiat 500X 2014-2020</p>', 'notes' => [],
                    'compatibilities' => [['brand' => 'Fiat', 'model' => '500X', 'year_from' => 2014, 'year_to' => 2020, 'evidence' => 'Fiat 500X 2014-2020', 'source_file' => null]]];
            }
        });
        $p = $this->import();
        $this->assertSame('FIAT', $p->brand);
        $this->assertSame('500X', $p->model);
        $this->assertSame(2014, (int) $p->year_from);
        $this->assertSame(2020, (int) $p->year_to);
        $this->assertSame('HYUNDAI', $p->metadata['requested_vehicle']['brand']);
        $this->assertSame('1:500X', app(AutogenesiTemplateExport::class)->rows($p)[0][AutogenesiTemplateExport::MODEL]);
    }

    public function test_chrome_extension_archive_is_admin_only_and_has_no_cookie_permission(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/autogenesi/chrome-extension')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/autogenesi/chrome-extension')->assertDownload('autogenesi-chrome.zip');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path('autogenesi/downloads/autogenesi-chrome.zip')));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertContains('activeTab', $manifest['permissions']);
        $this->assertNotContains('cookies', $manifest['permissions']);
        $this->assertNotContains('debugger', $manifest['permissions']);
        $this->assertNotFalse($zip->getFromName('capture.js'));
        $this->assertNotFalse($zip->getFromName('generic.js'));
        $this->assertSame('0.2.2', $manifest['version']);
        $this->assertContains('https://*/*', $manifest['optional_host_permissions']);
        $zip->close();
    }

    public function test_product_link_in_search_prepares_direct_import_without_starting_browser(): void
    {
        Process::preventStrayProcesses();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['links', 'best_price', 'links', 'best_price', 'links'] as $mode) {
            $this->post('/autogenesi/search', ['query' => ' https://es.aliexpress.com/item/1005008593938688.html?spm=tracking ', 'mode' => $mode])
                ->assertRedirect()->assertSessionHasNoErrors()
                ->assertSessionHas('autogenesi_direct_import.source_url', 'https://es.aliexpress.com/item/1005008593938688.html');
        }
        $this->get('/autogenesi')->assertInertia(fn (Assert $page) => $page->where('directImport.source_url', 'https://es.aliexpress.com/item/1005008593938688.html')->where('search', null));
        $this->assertDatabaseCount('autogenesi_products', 0);
        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_search_rejects_urls_that_are_not_aliexpress_product_pages(): void
    {
        Process::preventStrayProcesses();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['https://example.com/item/123.html', 'https://es.aliexpress.com/w/search.html', 'https://es.aliexpress.com:8000/item/123.html', 'https://user@es.aliexpress.com/item/123.html'] as $url) {
            $this->post('/autogenesi/search', ['query' => $url])->assertRedirect()->assertSessionHasErrors('query');
        }
        Process::assertNothingRan();
    }

    public function test_command_imports_draft_and_creates_only_template_excel(): void
    {
        $this->artisan('autogenesi:import', ['source' => $this->dir.'/manifest.json', '--brand' => 'HYUNDAI', '--model' => 'Kona', '--from' => '2017', '--to' => '2020'])->assertSuccessful();
        $p = AutogenesiProduct::firstOrFail();
        $files = Storage::disk('local')->allFiles('autogenesi/exports/'.$p->id);
        $this->assertCount(1, $files);
        $this->assertSame('draft', $p->status);
        $this->assertStringEndsWith('-autogenesi.xlsx', $files[0]);
        $book = IOFactory::load(Storage::disk('local')->path($files[0]));
        $rows = $book->getSheetByName('Products')->toArray();
        $this->assertNotContains($p->source_url, $rows[1]);
        $this->assertContains('Active', $rows[1]);
    }
    public function test_draft_vehicle_editor_saves_multibrand_models_and_individual_year_ranges(): void
    {
        $p = $this->import();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $data = ['title' => $p->title, 'body_html' => $p->body_html, 'variants' => $p->variants,
            'vehicles' => [['brand' => 'Fiat', 'model' => '500X', 'year_from' => 2014, 'year_to' => 2020],
                ['brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2026]]];
        $this->patch('/autogenesi/'.$p->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $fresh = $p->fresh();
        $this->assertSame('FIAT | HYUNDAI', $fresh->brand);
        $this->assertSame('1:500X | 2:KONA', $fresh->model);
        $this->assertSame(2014, (int) $fresh->year_from);
        $this->assertSame(2026, (int) $fresh->year_to);
        $this->assertSame(2020, $fresh->metadata['vehicle_compatibilities'][0]['year_to']);
        $this->assertSame('manual', $fresh->metadata['vehicle_compatibilities'][0]['origin']);
        $this->assertSame($p->source_url, $fresh->source_url);
        $this->assertSame($p->handle, $fresh->handle);
        $this->assertSame('1:500X | 2:KONA', app(AutogenesiTemplateExport::class)->rows($fresh)[0][AutogenesiTemplateExport::MODEL]);
        $data['vehicles'][0]['year_to'] = 2010;
        $this->patch('/autogenesi/'.$p->id, $data)->assertSessionHasErrors('vehicles.0.year_to');
        $this->assertSame(2020, $p->fresh()->metadata['vehicle_compatibilities'][0]['year_to']);
    }

    public function test_import_accepts_twenty_variants_and_rejects_twenty_one(): void
    {
        $manifest = json_decode(file_get_contents($this->dir.'/manifest.json'), true);
        $variant = $manifest['variants'][0];
        $manifest['variants'] = array_map(fn ($i) => array_replace($variant, ['label' => 'Version '.$i, 'supplier_price' => (string) (100 + $i)]), range(1, 20));
        file_put_contents($this->dir.'/manifest.json', json_encode($manifest));
        $this->assertCount(20, $this->import()->variants);
        $manifest['variants'][] = array_replace($variant, ['label' => 'Version 21']);
        file_put_contents($this->dir.'/manifest.json', json_encode($manifest));
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->import();
    }

}
