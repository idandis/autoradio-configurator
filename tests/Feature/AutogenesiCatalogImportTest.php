<?php

namespace Tests\Feature;

use App\Models\AutogenesiProduct;
use App\Models\ConfiguratorProduct;
use App\Models\User;
use App\Services\AutogenesiArubaImages;
use App\Services\AutogenesiTemplateExport;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AutogenesiCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        Storage::fake('local');
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function draft(string $id = '123'): AutogenesiProduct
    {
        $images = [];
        foreach ([1, 2] as $i) {
            $photo = UploadedFile::fake()->image('photo.png', 400 + $i, 300);
            $bytes = file_get_contents($photo->getRealPath());
            $path = 'drafts/'.$id.'/'.$i.'.png';
            Storage::disk('local')->put($path, $bytes);
            $images[] = ['path' => $path, 'source_url' => null, 'uploaded' => true, 'role' => $i === 2 ? 'cruscotto_prima_dopo' : 'prodotto',
                'sha256' => hash('sha256', $bytes), 'width' => 400 + $i, 'height' => 300];
        }

        return AutogenesiProduct::create(['source_id' => $id, 'source_url' => 'https://es.aliexpress.com/item/'.$id.'.html',
            'handle' => 'peugeot-2008-'.$id, 'status' => 'draft', 'title' => 'Pantalla 10" 1DIN Peugeot 2008', 'body_html' => '<p>Descrizione completa.</p>',
            'brand' => 'Peugeot', 'model' => '2008', 'year_from' => 2013, 'year_to' => 2019, 'images' => $images, 'metadata' => [],
            'variants' => [['sku' => 'AE-'.$id.'-1', 'label' => '4GB 64GB', 'supplier_price' => '120', 'selling_price' => '251'],
                ['sku' => 'AE-'.$id.'-2', 'label' => '8GB 128GB', 'supplier_price' => '200', 'selling_price' => '479']]]);
    }

    public function test_explicit_approval_imports_catalog_variants_and_portable_image_files(): void
    {
        $draft = $this->draft();
        $this->assertDatabaseCount('configurator_products', 0);
        $this->post('/autogenesi/'.$draft->id.'/import-configurator')->assertRedirect()->assertSessionHasNoErrors();
        $product = ConfiguratorProduct::firstOrFail();
        $this->assertSame('PEUGEOT', $product->brand);
        $this->assertSame('2008', $product->model);
        $this->assertSame('1DIN', $product->meta['din']);
        $this->assertSame($draft->body_html, $product->body_html);
        $this->assertSame('251.00', $product->price_min);
        $this->assertCount(2, $product->variants);
        foreach ($product->meta['gallery_images'] as $index => $url) {
            $this->assertStringStartsWith('/storage/autogenesi/catalog/', $url);
            $this->assertSame(Storage::disk('local')->get($draft->images[$index]['path']), Storage::disk('public')->get(substr($url, strlen('/storage/'))));
        }
        $this->assertSame($product->meta['gallery_images'][1], $product->meta['original_dashboard_images'][0]['url']);
        $this->assertSame($product->id, $draft->fresh()->metadata['configurator_import']['product_id']);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('', app(AutogenesiTemplateExport::class)->rows($draft->fresh())[0]['ID']);
        $this->getJson('/configurator/catalog/products/'.$product->id)->assertOk()
            ->assertJsonPath('product.images.0', $product->image_url)
            ->assertJsonPath('product.bodyHtml', $draft->body_html);
        $this->getJson('/configurator/catalog/specific-screens?brand=PEUGEOT&model=2008&year=2016')->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.originalDashboardImages.0.url', $product->meta['gallery_images'][1]);
        Http::assertNothingSent();
    }

    public function test_reimport_updates_same_catalog_preserving_variant_ids_and_removing_deleted_variants(): void
    {
        $draft = $this->draft();
        $url = '/autogenesi/'.$draft->id.'/import-configurator';
        $this->post($url)->assertSessionHasNoErrors();
        $product = ConfiguratorProduct::firstOrFail();
        $variantId = $product->variants()->where('sku', 'AE-123-1')->first()->id;
        $variants = [$draft->variants[0]];
        $variants[0]['selling_price'] = '399';
        $draft->update(['variants' => $variants, 'body_html' => '<p>Descrizione aggiornata.</p>']);
        $this->post($url)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('configurator_products', 1);
        $this->assertSame('399.00', $product->fresh()->price_min);
        $this->assertSame($variantId, $product->variants()->first()->id);
        $this->assertSame('399.00', $product->variants()->first()->price);
        $this->assertCount(1, $product->fresh()->variants);
        $this->assertCount(2, Storage::disk('public')->allFiles('autogenesi/catalog'));
    }

    public function test_missing_photo_rolls_back_catalog_and_any_new_public_files(): void
    {
        $draft = $this->draft();
        Storage::disk('local')->delete($draft->images[1]['path']);
        $this->post('/autogenesi/'.$draft->id.'/import-configurator')->assertRedirect()->assertSessionHasErrors('catalog');
        $this->assertDatabaseCount('configurator_products', 0);
        $this->assertDatabaseCount('configurator_variants', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertArrayNotHasKey('configurator_import', $draft->fresh()->metadata);
    }

    public function test_catalog_import_rejects_missing_price_and_requires_local_admin(): void
    {
        $draft = $this->draft();
        $url = '/autogenesi/'.$draft->id.'/import-configurator';
        $variants = $draft->variants;
        $variants[1]['selling_price'] = null;
        $draft->update(['variants' => $variants]);
        $this->post($url)->assertSessionHasErrors('catalog');
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->post($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->app['env'] = 'production';
        $this->post($url)->assertForbidden();
        $this->assertDatabaseCount('configurator_products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_bulk_workbook_groups_products_and_writes_body_and_metafields_only_once_per_product(): void
    {
        $first = $this->draft('123');
        $second = $this->draft('456');
        $this->draft('789')->update(['status' => 'archived']);
        $name = 'autogenesi-catalogo-bozze-'.now('Europe/Rome')->format('Y-m-d').'.xlsx';
        $this->get('/autogenesi/export-all')->assertDownload($name);
        $book = IOFactory::load(Storage::disk('local')->path('autogenesi/exports/catalogo/'.$name));
        $rows = $book->getSheetByName('Products')->toArray();
        $headers = array_shift($rows);
        $rows = array_map(fn ($r) => array_combine($headers, $r), $rows);
        $this->assertCount(4, $rows);
        $this->assertSame([$first->handle, $first->handle, $second->handle, $second->handle], array_column($rows, 'Handle'));
        $this->assertSame([$first->body_html, null, $second->body_html, null], array_column($rows, 'Body HTML'));
        $this->assertSame(['PEUGEOT', null, 'PEUGEOT', null], array_column($rows, AutogenesiTemplateExport::BRAND));
        $this->assertSame(['Active', 'Active', 'Active', 'Active'], array_column($rows, 'Status'));
        $this->assertSame(['Products'], $book->getSheetNames());
        Http::assertNothingSent();
    }

    public function test_export_prepares_filezilla_photo_folder_matching_excel_urls_without_publishing_products(): void
    {
        $draft = $this->draft();
        $this->get('/autogenesi/'.$draft->id.'/export')->assertDownload();
        $rows = app(AutogenesiTemplateExport::class)->rows($draft);
        foreach ($draft->images as $index => $image) {
            $url = $rows[$index]['Image Src'];
            $this->assertStringStartsWith('https://www.autoradioitaliano.it/storage/autogenesi/catalog/123/', $url);
            $relative = substr(parse_url($url, PHP_URL_PATH), strlen('/storage/'));
            $bytes = Storage::disk('public')->get($relative);
            $this->assertSame($image['sha256'], hash('sha256', $bytes));
            $this->assertSame(Storage::disk('local')->get($image['path']), $bytes);
            Storage::disk('public')->put($relative, 'damaged');
        }
        app(AutogenesiTemplateExport::class)->rows($draft);
        foreach ($draft->images as $image) {
            $file = app(AutogenesiArubaImages::class)->descriptor($draft, $image);
            $this->assertSame($image['sha256'], hash('sha256', Storage::disk('public')->get($file['relative'])));
        }
        $this->assertDatabaseCount('configurator_products', 0);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('autogenesi/deploy'));
        Http::assertNothingSent();
    }

    public function test_filezilla_preparation_rejects_stale_photo_hashes(): void
    {
        $draft = $this->draft();
        $image = $draft->images[0];
        $replacement = UploadedFile::fake()->image('changed.png', 500, 500);
        Storage::disk('local')->put($image['path'], file_get_contents($replacement->getRealPath()));
        $this->expectExceptionMessage('Foto modificata');
        app(AutogenesiArubaImages::class)->descriptor($draft, $image);
    }

    public function test_bulk_export_requires_admin_and_reports_empty_drafts(): void
    {
        $this->get('/autogenesi/export-all')->assertStatus(409);
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/autogenesi/export-all')->assertForbidden();
    }

    public function test_dashboard_generation_requires_real_web_references_and_preserves_existing_images(): void
    {
        $draft = $this->draft();
        $original = $draft->images;
        config(['services.openai.api_key' => 'test', 'services.openai.text_model' => 'gpt-5-mini', 'services.openai.image_model' => 'gpt-image-1-mini']);
        $sources = ['https://www.peugeot.com/brochure', 'https://www.autocar.co.uk/review'];
        $image = imagecreatetruecolor(1024, 1024);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Http::fake([
            'api.openai.com/v1/responses' => Http::sequence()
                ->push(['status' => 'completed', 'output' => [
                    ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['sources' => array_map(fn ($url) => ['url' => $url], $sources)]],
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Original dashboard research.']]],
                ]])
                ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'supported' => true, 'description' => str_repeat('Original factory center console, paired vents above the radio, original HVAC controls below. ', 3), 'reason' => '', 'source_urls' => $sources,
                ])]]]]]),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode($bytes)]]]),
        ]);
        $this->post('/autogenesi/'.$draft->id.'/dashboard-image')->assertRedirect()->assertSessionHasNoErrors();
        $photos = $draft->fresh()->images;
        $this->assertSame($original, array_slice($photos, 0, count($original)));
        $photo = end($photos);
        $this->assertTrue($photo['ai_generated']);
        $this->assertSame('cruscotto_originale', $photo['role']);
        $this->assertSame($sources, $photo['web_sources']);
        $this->assertSame($bytes, Storage::disk('local')->get($photo['path']));
        $this->assertDatabaseCount('configurator_products', 0);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/responses') && ($r['tool_choice'] ?? null) === 'required' && $r['tools'][0]['type'] === 'web_search');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/images/generations') && str_contains($r['prompt'], 'Peugeot') && str_contains($r['prompt'], '2013'));
    }

    public function test_dashboard_without_web_evidence_does_not_generate_or_change_images(): void
    {
        $draft = $this->draft();
        config(['services.openai.api_key' => 'test']);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Unsupported guess.']]],
        ]])]);
        $this->post('/autogenesi/'.$draft->id.'/dashboard-image')->assertRedirect()->assertSessionHasErrors('dashboard');
        $this->assertSame($draft->images, $draft->fresh()->images);
        Http::assertSentCount(1);
    }
    public function test_image_order_and_dashboard_flag_are_saved_and_used_by_exports_and_catalog(): void
    {
        $draft = $this->draft();
        $ids = array_column($draft->images, 'sha256');
        $url = '/autogenesi/'.$draft->id.'/images/layout';
        $this->patch($url, ['dashboard_image_id' => $ids[0], 'shopify_file_reference' => 'cruscotto-peugeot.jpg'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->patch($url, ['order' => array_reverse($ids)])->assertRedirect()->assertSessionHasNoErrors();
        $current = $draft->fresh();
        $this->assertSame(array_reverse($ids), array_column($current->images, 'sha256'));
        $this->assertSame($ids[0], \App\Services\AutogenesiImageLayout::dashboard($current)['sha256']);
        $rows = app(AutogenesiTemplateExport::class)->rows($current);
        $this->assertStringContainsString($ids[1], $rows[0]['Image Src']);
        $this->assertSame('cruscotto-peugeot.jpg', $rows[0][AutogenesiTemplateExport::DASHBOARD]);
        $this->assertSame('', $rows[1][AutogenesiTemplateExport::DASHBOARD]);
        $this->assertSame($draft->variants, $current->variants);
        $this->post('/autogenesi/'.$draft->id.'/import-configurator')->assertRedirect()->assertSessionHasNoErrors();
        $p = ConfiguratorProduct::firstOrFail();
        $this->assertStringContainsString($ids[1], $p->image_url);
        $this->assertCount(1, $p->meta['original_dashboard_images']);
        $this->assertStringContainsString($ids[0], $p->meta['original_dashboard_images'][0]['url']);
        $this->patch($url, ['dashboard_image_id' => null])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(\App\Services\AutogenesiImageLayout::dashboard($draft->fresh()));
        $this->assertSame('', app(AutogenesiTemplateExport::class)->rows($draft->fresh())[0][AutogenesiTemplateExport::DASHBOARD]);
    }

    public function test_multiple_dashboard_photos_export_comma_separated_and_reach_catalog(): void
    {
        $draft = $this->draft();
        $ids = array_column($draft->images, 'sha256');
        $url = '/autogenesi/'.$draft->id.'/images/layout';
        $this->patch($url, ['dashboard_image_ids' => $ids])->assertSessionHasNoErrors();
        $this->patch($url, ['reference_image_id' => $ids[0], 'shopify_file_reference' => 'originale-frontale.png'])->assertSessionHasNoErrors();
        $this->assertCount(count($ids), \App\Services\AutogenesiImageLayout::dashboards($draft->fresh()));
        $this->patch($url, ['order' => array_reverse($ids)])->assertSessionHasNoErrors();
        $rows = app(AutogenesiTemplateExport::class)->rows($draft->fresh());
        $this->assertSame($ids[1].'.png, originale-frontale.png', $rows[0][AutogenesiTemplateExport::DASHBOARD]);
        $this->assertSame('', $rows[1][AutogenesiTemplateExport::DASHBOARD]);
        $this->post('/autogenesi/'.$draft->id.'/import-configurator')->assertSessionHasNoErrors();
        $p = ConfiguratorProduct::firstOrFail();
        $this->assertCount(2, $p->meta['original_dashboard_images']);
        $this->assertStringContainsString($ids[1], $p->meta['original_dashboard_images'][0]['url']);
        $file = app(\App\Services\ImportedProductsExport::class)->write(now('Europe/Rome')->format('Y-m-d'));
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $data = $book->getActiveSheet()->toArray();
        $col = array_search(AutogenesiTemplateExport::DASHBOARD, $data[0]);
        $this->assertSame($rows[0][AutogenesiTemplateExport::DASHBOARD], $data[1][$col]);
        $parser = new \ReflectionMethod(\App\Services\ConfiguratorCsvImporter::class, 'parseFileList');
        $images = ['https://example.com/'.$ids[1].'.png', 'https://example.com/originale-frontale.png'];
        $parsed = $parser->invoke(app(\App\Services\ConfiguratorCsvImporter::class), $data[1][$col], $images[0], $images);
        $this->assertSame($images, array_column($parsed, 'url'));
        $this->patch($url, ['dashboard_image_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame([], \App\Services\AutogenesiImageLayout::dashboards($draft->fresh()));
    }

    public function test_image_layout_rejects_missing_duplicate_and_unknown_images_and_external_references(): void
    {
        $draft = $this->draft();
        $ids = array_column($draft->images, 'sha256');
        $url = '/autogenesi/'.$draft->id.'/images/layout';
        foreach ([['order' => [$ids[0]]], ['order' => [$ids[0], $ids[0]]], ['dashboard_image_id' => str_repeat('a', 64)],
            ['dashboard_image_id' => $ids[0], 'shopify_file_reference' => 'https://example.com/a.jpg']] as $data) {
            $this->patch($url, $data)->assertRedirect()->assertSessionHasErrors();
            $this->assertSame($draft->images, $draft->fresh()->images);
        }
        $this->patch($url, ['dashboard_image_id' => $ids[1], 'shopify_file_reference' => 'gid://shopify/MediaImage/123'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('gid://shopify/MediaImage/123', app(AutogenesiTemplateExport::class)->rows($draft->fresh())[0][AutogenesiTemplateExport::DASHBOARD]);
    }

    public function test_bulk_catalog_import_deletes_all_drafts_only_after_success_and_keeps_catalog_images_and_supplier_link(): void
    {
        $first = $this->draft('123');
        $second = $this->draft('456');
        $paths = array_merge(array_column($first->images, 'path'), array_column($second->images, 'path'));
        $this->post('/autogenesi/import-all-configurator')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('autogenesi_products', 0);
        $this->assertDatabaseCount('configurator_products', 2);
        $this->assertDatabaseCount('configurator_variants', 4);
        $this->assertSame($first->source_url, ConfiguratorProduct::where('handle', $first->handle)->firstOrFail()->meta['autogenesi']['source_url']);
        foreach (ConfiguratorProduct::all() as $p) foreach ($p->meta['gallery_images'] as $url) Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
        Storage::disk('local')->assertMissing($paths);
        $this->post('/autogenesi/import-all-configurator')->assertSessionHasErrors('catalog_all');
        $this->assertDatabaseCount('configurator_products', 2);
    }

    public function test_bulk_import_failure_keeps_every_draft_and_rolls_back_all_catalog_changes(): void
    {
        $first = $this->draft('123');
        $second = $this->draft('456');
        $variants = $second->variants;
        $variants[0]['selling_price'] = null;
        $second->update(['variants' => $variants]);
        $this->post('/autogenesi/import-all-configurator')->assertRedirect()->assertSessionHasErrors('catalog_all');
        $this->assertDatabaseCount('autogenesi_products', 2);
        $this->assertDatabaseCount('configurator_products', 0);
        $this->assertDatabaseCount('configurator_variants', 0);
        Storage::disk('local')->assertExists(array_column($first->images, 'path'));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertArrayNotHasKey('configurator_import', $first->fresh()->metadata);
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->post('/autogenesi/import-all-configurator')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->app['env'] = 'production';
        $this->post('/autogenesi/import-all-configurator')->assertForbidden();
    }

    public function test_multibrand_import_keeps_model_years_separate_in_configurator(): void
    {
        $draft = $this->draft();
        $ranges = [['brand' => 'FIAT', 'model' => '500X', 'year_from' => 2014, 'year_to' => 2020],
            ['brand' => 'HYUNDAI', 'model' => 'KONA', 'year_from' => 2019, 'year_to' => 2023]];
        $draft->update(['brand' => 'FIAT | HYUNDAI', 'model' => '1:500X | 2:KONA', 'year_from' => 2014, 'year_to' => 2023, 'metadata' => ['vehicle_compatibilities' => $ranges]]);
        $this->assertSame('1:500X | 2:Kona', app(AutogenesiTemplateExport::class)->rows($draft)[0][AutogenesiTemplateExport::MODEL]);
        $this->post('/autogenesi/'.$draft->id.'/import-configurator')->assertRedirect()->assertSessionHasNoErrors();
        $p = ConfiguratorProduct::firstOrFail();
        $this->assertSame($ranges, $p->meta['vehicle_compatibilities']);
        $this->getJson('/configurator/catalog/specific-screens?brand=HYUNDAI&model=KONA&year=2018')->assertOk()->assertJsonCount(0, 'products');
        $this->getJson('/configurator/catalog/specific-screens?brand=HYUNDAI&model=KONA&year=2021')->assertOk()->assertJsonCount(1, 'products');
        $this->getJson('/configurator/catalog/specific-screens?brand=FIAT&model=500X&year=2021')->assertOk()->assertJsonCount(0, 'products');
    }

}
