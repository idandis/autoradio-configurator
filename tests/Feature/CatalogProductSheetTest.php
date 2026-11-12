<?php

namespace Tests\Feature;

use App\Models\AutogenesiProduct;
use App\Models\ConfiguratorProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogProductSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        Storage::fake('local'); Storage::fake('public');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function product(): ConfiguratorProduct
    {
        $p = ConfiguratorProduct::create(['handle' => 'existing-radio', 'category' => 'screen', 'title' => 'Radio', 'body_html' => '<p>Originale</p>',
            'brand' => 'FORD', 'model' => 'Fiesta', 'year_from' => 2008, 'year_to' => 2017, 'price_min' => 310.25,
            'meta' => ['shopify_product_id' => '123', 'source_url' => 'https://es.aliexpress.com/item/123.html']]);
        $p->variants()->create(['title' => '8Core 4G 64G', 'sku' => 'original-sku', 'shopify_variant_id' => '456', 'price' => 310.25,
            'meta' => ['variant_cost' => '100.00', 'source_sku_id' => '789']]);
        return $p;
    }

    public function test_shared_editor_updates_catalog_without_creating_drafts_or_changing_identity(): void
    {
        $p = $this->product(); $v = $p->variants()->first();
        $this->get('/imported-products/'.$p->id.'/sheet')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Autogenesi')->where('catalogEdit', true)->has('products', 1));
        $this->from('/imported-products/'.$p->id.'/sheet')->patch('/imported-products/'.$p->id.'/sheet', [
            'title' => 'Radio aggiornata', 'body_html' => '<p>Nuovo testo</p>', 'additional_source_urls' => [],
            'vehicles' => [['brand' => 'FORD', 'model' => 'Fiesta', 'year_from' => 2009, 'year_to' => 2017]],
            'variants' => [['sku' => 'CAT-'.$v->id, 'label' => '8Core 4GB 64GB', 'supplier_price' => '100.00', 'selling_price' => '311.25']],
        ])->assertSessionHasNoErrors()->assertRedirect('/imported-products/'.$p->id.'/sheet');
        $this->assertSame(0, AutogenesiProduct::count());
        $this->assertSame('123', $p->fresh()->meta['shopify_product_id']);
        $this->assertSame('existing-radio', $p->fresh()->handle);
        $this->assertSame('456', $v->fresh()->shopify_variant_id);
        $this->assertSame('original-sku', $v->fresh()->sku);
        $this->assertSame('789', $v->fresh()->meta['source_sku_id']);
        $this->assertSame('311.25', $v->fresh()->price);
        $this->assertSame(2009, $p->fresh()->year_from);
    }

    public function test_universal_radio_saves_without_vehicle_compatibility(): void
    {
        $p = $this->product();
        $p->update(['brand' => null, 'model' => 'Universal', 'year_from' => null, 'year_to' => null]);
        $v = $p->variants()->firstOrFail();
        $url = '/imported-products/'.$p->id.'/sheet';
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('products.0.vehicle_groups', []));
        $this->from($url)->patch($url, ['title' => 'Autoradio Universal aggiornata', 'body_html' => '<p>Universale</p>',
            'variants' => [['sku' => 'CAT-'.$v->id, 'label' => '8Core 4G 64G', 'supplier_price' => '100.00', 'selling_price' => '320.00']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Autoradio Universal aggiornata', $p->fresh()->title);
        $this->assertNull($p->fresh()->brand);
        $this->assertSame('Universal', $p->fresh()->model);
    }

    public function test_images_upload_crop_layout_and_removal_update_catalog_immediately(): void
    {
        $p = $this->product(); $url = '/imported-products/'.$p->id.'/sheet';
        $this->from($url)->post($url.'/images', ['photos' => [UploadedFile::fake()->image('photo.png', 600, 400)], 'role' => 'prodotto'])->assertSessionHasNoErrors();
        $image = $p->fresh()->meta['product_sheet_images'][0];
        $this->assertTrue(Storage::disk('public')->exists(substr($p->fresh()->image_url, 9)));
        $this->get($url.'/images/0')->assertOk();
        $this->from($url)->post($url.'/images/0/crop', ['image_id' => $image['sha256'], 'x' => 0, 'y' => 0, 'width' => 320, 'height' => 320])->assertSessionHasNoErrors();
        $image = $p->fresh()->meta['product_sheet_images'][0];
        $this->assertSame(320, $image['width']);
        $this->from($url)->patch($url.'/images/layout', ['dashboard_image_ids' => [$image['sha256']]])->assertSessionHasNoErrors();
        $this->assertSame($p->fresh()->image_url, $p->fresh()->meta['original_dashboard_images'][0]['url']);
        $this->from($url)->delete($url.'/images/0', ['image_id' => $image['sha256']])->assertSessionHasNoErrors();
        $this->assertSame([], $p->fresh()->meta['gallery_images']);
        $this->assertSame(0, AutogenesiProduct::count());
    }

    public function test_ai_cleanup_updates_imported_product_photo_from_original_reference(): void
    {
        config(['services.openai.api_key' => 'test', 'services.openai.image_model' => 'gpt-image-1-mini']);
        $p = $this->product(); $url = '/imported-products/'.$p->id.'/sheet';
        $this->post($url.'/images', ['photos' => [UploadedFile::fake()->image('dashboard.png', 600, 400)], 'role' => 'cruscotto_prima_dopo'])->assertSessionHasNoErrors();
        $before = $p->fresh()->meta['product_sheet_images'][0];
        $result = UploadedFile::fake()->image('clean.png', 1024, 1024);
        \Illuminate\Support\Facades\Http::fake(['api.openai.com/v1/images/edits' => \Illuminate\Support\Facades\Http::response(['data' => [['b64_json' => base64_encode(file_get_contents($result->getRealPath()))]]])]);
        $mask = imagecreatetruecolor(600, 400);
        imagefilledrectangle($mask, 100, 100, 130, 130, imagecolorallocate($mask, 255, 255, 255));
        ob_start(); imagepng($mask); $maskBytes = ob_get_clean(); imagedestroy($mask);
        $this->post($url.'/images/0/clean-ai', ['image_id' => $before['sha256'], 'mask' => UploadedFile::fake()->createWithContent('mask.png', $maskBytes)])->assertSessionHasNoErrors();
        $after = $p->fresh()->meta['product_sheet_images'][0];
        $this->assertSame('dashboard_clean', $after['ai_edit_mode']);
        $this->assertSame([1024, 1024], [$after['width'], $after['height']]);
        $this->assertSame($before, $after['original']);
        $this->assertSame(0, AutogenesiProduct::count());
    }

    public function test_catalog_photo_can_be_upscaled_without_creating_draft(): void
    {
        $p = $this->product();
        $url = '/imported-products/'.$p->id.'/sheet';
        $this->post($url.'/images', ['photos' => [UploadedFile::fake()->image('small.png', 80, 50)], 'role' => 'cruscotto_prima_dopo'])
            ->assertSessionHasNoErrors();
        $before = $p->fresh()->meta['product_sheet_images'][0];
        $this->post($url.'/images/0/upscale', ['image_id' => $before['sha256']])->assertSessionHasNoErrors();
        $after = $p->fresh()->meta['product_sheet_images'][0];
        $this->assertSame([160, 100], [$after['width'], $after['height']]);
        $this->assertSame($before['sha256'], $after['original']['sha256']);
        $this->post($url.'/images/0/restore', ['image_id' => $after['sha256']])->assertSessionHasNoErrors();
        $this->assertSame($before['sha256'], $p->fresh()->meta['product_sheet_images'][0]['sha256']);
        $this->assertSame(0, AutogenesiProduct::count());
    }

    public function test_camera_cost_changes_do_not_apply_radio_rule_and_missing_fields_can_be_saved(): void
    {
        $p = $this->product(); $p->update(['category' => 'camera', 'brand' => null, 'model' => null, 'year_from' => null, 'year_to' => null]);
        $v = $p->variants()->first();
        $this->patch('/imported-products/'.$p->id.'/sheet', ['title' => 'Camera', 'body_html' => '',
            'variants' => [['sku' => 'CAT-'.$v->id, 'label' => 'AHD', 'supplier_price' => '12.00', 'selling_price' => '40.25']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('40.25', $v->fresh()->price);
        $this->assertSame('12.00', $v->fresh()->meta['variant_cost']);
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/imported-products/'.$p->id.'/sheet')->assertForbidden();
        $this->patch('/imported-products/'.$p->id.'/sheet', [])->assertForbidden();
    }

    public function test_reimport_adds_supplier_photos_without_duplicates_or_changes_to_product_data(): void
    {
        $p = $this->product(); $url = '/imported-products/'.$p->id.'/sheet';
        $file = UploadedFile::fake()->image('supplier.png', 600, 400);
        $bytes = file_get_contents($file->getRealPath());
        \Illuminate\Support\Facades\Http::fake(['https://ae01.alicdn.com/new.png' => \Illuminate\Support\Facades\Http::response($bytes, 200)]);
        $this->mock(\App\Services\AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->twice()->with(\Mockery::type('int'), '123')->andReturn([
            'ae_multimedia_info_dto' => ['image_urls' => 'https://ae01.alicdn.com/new.png'],
            'ae_item_base_info_dto' => ['detail' => '<img src="https://ae01.alicdn.com/new.png">'],
        ]));
        $this->mock(\App\Services\AutogenesiImageSelection::class, fn ($m) => $m->shouldReceive('select')->once()->andReturnUsing(fn ($images) => [array_replace($images[0], ['role' => 'prodotto'])]));
        $v = $p->variants()->first(); $before = $v->getAttributes();
        $this->from($url)->post($url.'/reimport-images')->assertSessionHasNoErrors();
        $this->assertCount(1, $p->fresh()->meta['product_sheet_images']);
        $im = $p->fresh()->meta['product_sheet_images'][0];
        $this->assertSame(600, $im['width']); $this->assertSame(400, $im['height']);
        Storage::disk('public')->assertExists(substr($p->fresh()->image_url, 9));
        $this->from($url)->post($url.'/reimport-images')->assertSessionHasNoErrors();
        $this->assertCount(1, $p->fresh()->meta['product_sheet_images']);
        $this->assertSame($before, $v->fresh()->getAttributes());
        $this->assertSame(0, AutogenesiProduct::count());
    }

    public function test_reimport_persists_only_curated_product_accessory_and_before_after_photos(): void
    {
        $p = $this->product(); $urls = array_map(fn ($i) => 'https://ae01.alicdn.com/'.$i.'.png', range(0, 7));
        \Illuminate\Support\Facades\Http::fake(function ($request) {
            $i = (int) basename(parse_url($request->url(), PHP_URL_PATH), '.png');
            $im = imagecreatetruecolor(600, 400); imagefill($im, 0, 0, imagecolorallocate($im, $i * 25, 40, 90));
            ob_start(); imagepng($im); $bytes = ob_get_clean(); imagedestroy($im);
            return \Illuminate\Support\Facades\Http::response($bytes);
        });
        $this->mock(\App\Services\AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andReturn([
            'ae_multimedia_info_dto' => ['image_urls' => implode(';', array_slice($urls, 0, 7))],
            'ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => [['ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [['sku_image' => $urls[7]]]]]]],
        ]));
        $this->mock(\App\Services\AutogenesiImageSelection::class, fn ($m) => $m->shouldReceive('select')->once()->andReturnUsing(function ($images) use ($urls) {
            $this->assertSame($urls[7], $images[0]['source_url']);
            return array_map(fn ($i) => array_replace($images[$i], ['role' => $i < 3 ? 'prodotto' : ($i === 3 ? 'accessori' : 'cruscotto_prima_dopo')]), range(0, 4));
        }));
        $this->post('/imported-products/'.$p->id.'/sheet/reimport-images')->assertSessionHasNoErrors();
        $images = $p->fresh()->meta['product_sheet_images'];
        $this->assertCount(5, $images);
        $this->assertSame(['prodotto' => 3, 'accessori' => 1, 'cruscotto_prima_dopo' => 1], array_count_values(array_column($images, 'role')));
        $this->assertSame([], Storage::disk('local')->directories('autogenesi/captures'));
    }

    public function test_reimport_from_replacement_link_adds_new_photos_even_if_old_gallery_is_full(): void
    {
        $p = $this->product();
        $meta = $p->meta;
        $meta['source_url'] = 'https://es.aliexpress.com/item/999.html';
        $meta['product_sheet_images'] = array_map(fn ($i) => [
            'path' => 'old/'.$i.'.jpg', 'sha256' => hash('sha256', 'old-'.$i),
            'source_url' => 'https://ae01.alicdn.com/old-'.$i.'.jpg',
            'catalog_url' => 'https://cdn.shopify.com/old-'.$i.'.jpg', 'role' => 'prodotto',
            'width' => 600, 'height' => 400,
        ], range(1, 3));
        $p->update(['meta' => $meta]);
        $file = UploadedFile::fake()->image('new.jpg', 600, 400);
        \Illuminate\Support\Facades\Http::fake(['https://ae01.alicdn.com/new.jpg' => \Illuminate\Support\Facades\Http::response(file_get_contents($file->getRealPath()))]);
        $this->mock(\App\Services\AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->with(\Mockery::type('int'), '999')->andReturn([
            'ae_multimedia_info_dto' => ['image_urls' => 'https://ae01.alicdn.com/new.jpg'],
        ]));
        $this->mock(\App\Services\AutogenesiImageSelection::class, fn ($m) => $m->shouldReceive('select')->once()->andReturnUsing(fn ($images) => [array_replace($images[0], ['role' => 'prodotto'])]));
        $this->post('/imported-products/'.$p->id.'/sheet/reimport-images')->assertSessionHasNoErrors();
        $images = $p->fresh()->meta['product_sheet_images'];
        $this->assertCount(4, $images);
        $this->assertSame('https://ae01.alicdn.com/new.jpg', $images[3]['source_url']);
    }

    public function test_reimport_failure_preserves_existing_images(): void
    {
        $p = $this->product(); $p->update(['image_url' => 'https://cdn.shopify.com/keep.jpg']);
        $this->mock(\App\Services\AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andThrow(new \RuntimeException('AliExpress non raggiungibile.')));
        $this->post('/imported-products/'.$p->id.'/sheet/reimport-images')->assertSessionHasErrors('reimport_images');
        $this->assertSame('https://cdn.shopify.com/keep.jpg', $p->fresh()->image_url);
        $this->assertArrayNotHasKey('product_sheet_images', $p->fresh()->meta);
    }

    public function test_description_from_link_reads_supplier_text_and_only_updates_description(): void
    {
        $p = $this->product(); $v = $p->variants()->first(); $before = $v->getAttributes();
        $this->mock(\App\Services\AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andReturn([
            'ae_item_base_info_dto' => ['subject' => 'Original radio', 'detail' => '<p>Radio QLED 9 pulgadas</p>'],
            'ae_item_properties' => ['ae_item_property' => [['attr_name' => 'Screen', 'attr_value' => 'QLED 9']]],
        ]));
        $this->mock(\App\Services\AutogenesiDescription::class, fn ($m) => $m->shouldReceive('generate')->once()->withArgs(function ($data, $vehicle, $dir, $guard) {
            return str_contains($data['description']['text'], 'QLED 9 pulgadas') && $data['description']['specifications'][0]['value'] === 'QLED 9'
                && $vehicle['brand'] === 'FORD' && is_dir($dir) && $guard === false;
        })->andReturn(['title' => 'Must not replace title', 'body_html' => '<p>Descripción nueva</p>', 'notes' => ['Verificar conector']]));
        $this->post('/imported-products/'.$p->id.'/sheet/description-link')->assertSessionHasNoErrors();
        $this->assertSame('<p>Descripción nueva</p>', $p->fresh()->body_html);
        $this->assertSame('Radio', $p->fresh()->title);
        $this->assertSame('Originale', strip_tags($p->fresh()->meta['previous_copy']['body_html']));
        $this->assertSame(['Verificar conector'], $p->fresh()->meta['description_notes']);
        $this->assertSame($before, $v->fresh()->getAttributes());
        $this->assertSame(0, AutogenesiProduct::count());
        $this->assertSame([], Storage::disk('local')->directories('autogenesi/captures'));
    }

    public function test_description_json_returns_the_saved_text_and_reports_identical_results(): void
    {
        $p = $this->product();
        $this->mock(\App\Services\AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->twice()->andReturn([
            'ae_item_base_info_dto' => ['subject' => 'Radio', 'detail' => '<p>Original supplier text</p>'],
            'ae_item_properties' => ['ae_item_property' => [['attr_name' => 'Screen', 'attr_value' => '9']]],
        ]));
        $this->mock(\App\Services\AutogenesiDescription::class, fn ($m) => $m->shouldReceive('generate')->twice()->andReturn([
            'body_html' => '<p>New authoritative description</p>', 'notes' => [],
        ]));
        $url = '/imported-products/'.$p->id.'/sheet/description-link';
        $first = $this->postJson($url)->assertOk()->assertJsonPath('changed', true)->assertJsonPath('body_html', '<p>New authoritative description</p>')->assertJsonStructure(['generation' => ['id', 'generated_at'], 'source' => ['text', 'specifications']]);
        $this->assertSame($p->fresh()->body_html, $first->json('body_html'));
        $backup = $p->fresh()->meta['previous_copy'];
        $second = $this->postJson($url)->assertOk()->assertJsonPath('changed', false)->assertJsonPath('body_html', '<p>New authoritative description</p>');
        $this->assertNotSame($first->json('generation.id'), $second->json('generation.id'));
        $this->assertSame($backup, $p->fresh()->meta['previous_copy']);
    }

    public function test_description_generation_failure_keeps_current_text(): void
    {
        $p = $this->product();
        $this->mock(\App\Services\AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andReturn([
            'ae_item_base_info_dto' => ['detail' => '<p>Radio</p>'],
            'ae_item_properties' => ['ae_item_property' => [['attr_name' => 'Screen', 'attr_value' => '9']]],
        ]));
        $this->mock(\App\Services\AutogenesiDescription::class, fn ($m) => $m->shouldReceive('generate')->once()->andThrow(new \RuntimeException('Generazione incompleta.')));
        $this->post('/imported-products/'.$p->id.'/sheet/description-link')->assertSessionHasErrors('description_link');
        $this->assertSame('<p>Originale</p>', $p->fresh()->body_html);
    }

    public function test_dashboard_search_imports_a_real_photo_without_generating_one(): void
    {
        $p = $this->product();
        $meta = $p->meta;
        $meta['vehicle_compatibilities'] = [['brand' => 'FORD', 'model' => 'Fiesta', 'year_from' => 2005, 'year_to' => 2017]];
        $p->update(['meta' => $meta]);
        config(['services.openai.api_key' => 'test', 'services.openai.text_model' => 'gpt-5-mini', 'services.openai.image_model' => 'gpt-image-1-mini']);
        $sources = ['https://ford.com/fiesta', 'https://autoexpress.co.uk/fiesta'];
        $research = ['status' => 'completed', 'output' => [['type' => 'web_search_call', 'status' => 'completed', 'action' => ['sources' => array_map(fn ($url) => ['url' => $url], $sources)]],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Factory radio, original controls and dashboard verified.']]]]];
        $choice = ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
            'match' => true, 'whole_dashboard_visible' => true, 'index' => 0, 'reason' => 'Original dashboard photograph.',
        ])]]]]];
        $file = UploadedFile::fake()->image('dashboard.jpg', 900, 600);
        $bytes = file_get_contents($file->getRealPath());
        \Illuminate\Support\Facades\Http::fake([
            'api.openai.com/v1/responses' => \Illuminate\Support\Facades\Http::sequence()->push(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']])->push($research)->push($choice),
            'ford.com/fiesta' => \Illuminate\Support\Facades\Http::response('<html><meta property="og:image" content="https://ford.com/dashboard.jpg"></html>'),
            'autoexpress.co.uk/fiesta' => \Illuminate\Support\Facades\Http::response('<html></html>'),
            'ford.com/dashboard.jpg' => \Illuminate\Support\Facades\Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->post('/imported-products/'.$p->id.'/sheet/dashboard-image')->assertSessionHasNoErrors();
        $im = $p->fresh()->meta['product_sheet_images'][0];
        $this->assertFalse($im['ai_generated']); $this->assertSame('cruscotto_originale', $im['role']);
        $this->assertSame('https://ford.com/dashboard.jpg', $im['source_url']);
        $this->assertSame([900, 600], [$im['width'], $im['height']]);
        Storage::disk('public')->assertExists(substr($p->fresh()->image_url, 9));
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_ends_with($r->url(), '/responses') && $r['max_output_tokens'] === 16000 && $r['reasoning']['effort'] === 'low');
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_ends_with($r->url(), '/responses') && ($r['input'] ?? null) === 'FORD FIESTA 2005 INTERNI');
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($r) => str_contains($r->url(), '/images/generations'));
        $this->assertSame(0, AutogenesiProduct::count());
    }

    public function test_dashboard_search_uses_image_results_when_source_pages_have_no_photos(): void
    {
        $p = $this->product();
        config(['services.openai.api_key' => 'test', 'services.openai.text_model' => 'gpt-5-mini']);
        $research = ['status' => 'completed', 'output' => [['type' => 'web_search_call', 'status' => 'completed', 'action' => ['sources' => [['url' => 'https://example.com/article']]]]]];
        $choice = ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['match' => true, 'whole_dashboard_visible' => true, 'index' => 0, 'reason' => 'Factory dashboard'])]]]]];
        $file = UploadedFile::fake()->image('interior.jpg', 900, 600);
        $bytes = file_get_contents($file->getRealPath());
        $result = '<a class="ius" m="{&quot;murl&quot;:&quot;https://photos.example.com/xc60.jpg&quot;,&quot;purl&quot;:&quot;https://cars.example.com/volvo-xc60-2008&quot;}">Volvo XC60 2008 interior</a>';
        \Illuminate\Support\Facades\Http::fake([
            'api.openai.com/v1/responses' => \Illuminate\Support\Facades\Http::sequence()->push($research)->push($choice),
            'www.google.com/*' => \Illuminate\Support\Facades\Http::response(''),
            'www.bing.com/images/*' => \Illuminate\Support\Facades\Http::response($result),
            'photos.example.com/xc60.jpg' => \Illuminate\Support\Facades\Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
            'example.com/article' => \Illuminate\Support\Facades\Http::response('<html></html>'),
        ]);
        $this->post('/imported-products/'.$p->id.'/sheet/dashboard-image')->assertSessionHasNoErrors();
        $im = $p->fresh()->meta['product_sheet_images'][0];
        $this->assertSame('https://photos.example.com/xc60.jpg', $im['source_url']);
        $this->assertSame(['https://cars.example.com/volvo-xc60-2008'], $im['web_sources']);
    }

    public function test_dashboard_content_filter_does_not_retry_or_add_images(): void
    {
        $p = $this->product();
        config(['services.openai.api_key' => 'test', 'services.openai.text_model' => 'gpt-5-mini']);
        \Illuminate\Support\Facades\Http::fake(['api.openai.com/v1/responses' => \Illuminate\Support\Facades\Http::response(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'content_filter']])]);
        $this->post('/imported-products/'.$p->id.'/sheet/dashboard-image')->assertSessionHasErrors('dashboard');
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->assertArrayNotHasKey('product_sheet_images', $p->fresh()->meta);
    }

    public function test_stop_invalidates_old_requests_only_for_the_selected_sheet_and_allows_restart(): void
    {
        $p = $this->product(); $url = '/imported-products/'.$p->id.'/sheet';
        $epoch = $this->postJson($url.'/stop-processing')->assertOk()->json('epoch');
        $this->withHeader('X-Sheet-Epoch', 'initial')->patch($url, [])->assertSessionHasErrors('processing');
        $this->assertSame('Radio', $p->fresh()->title);
        $this->withHeader('X-Sheet-Epoch', $epoch)->post($url.'/variants', ['label' => 'Nuova', 'supplier_price' => '100', 'selling_price' => '300'])->assertSessionHasNoErrors();
        $this->assertSame(2, $p->variants()->count());
        $this->assertSame('initial', app(\App\Services\ProductSheetProcessing::class)->epoch('draft', $p->id));
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->postJson($url.'/stop-processing')->assertForbidden();
    }

    public function test_stop_during_research_prevents_followup_ai_calls_and_saving_images(): void
    {
        $p = $this->product();
        config(['services.openai.api_key' => 'test', 'services.openai.text_model' => 'gpt-5-mini']);
        \Illuminate\Support\Facades\Http::fake(function () use ($p) {
            app(\App\Services\ProductSheetProcessing::class)->stop('catalog', $p->id);
            return \Illuminate\Support\Facades\Http::response(['status' => 'completed', 'output' => []]);
        });
        $this->withHeader('X-Sheet-Epoch', 'initial')->post('/imported-products/'.$p->id.'/sheet/dashboard-image')->assertSessionHasErrors('processing');
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->assertArrayNotHasKey('product_sheet_images', $p->fresh()->meta);
        $this->assertSame(0, AutogenesiProduct::count());
    }

    public function test_manual_variants_keep_existing_shopify_ids(): void
    {
        $p = $this->product(); $v = $p->variants()->first(); $url = '/imported-products/'.$p->id.'/sheet';
        $this->post($url.'/variants', ['label' => 'Nuova variante', 'supplier_price' => '150.00', 'selling_price' => '450.25'])->assertSessionHasNoErrors();
        $this->assertSame(2, $p->variants()->count());
        $this->assertSame('456', $v->fresh()->shopify_variant_id);
        $new = $p->variants()->where('id', '!=', $v->id)->first();
        $this->delete($url.'/variants', ['sku' => 'CAT-'.$new->id])->assertSessionHasNoErrors();
        $this->assertSame(1, $p->variants()->count());
        $this->assertSame(0, AutogenesiProduct::count());
    }
}
