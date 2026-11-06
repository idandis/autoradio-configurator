<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\InstallationZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportedProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_descriptions_without_changing_the_spanish_original(): void
    {
        $product = ConfiguratorProduct::create([
            'handle' => 'description-editor', 'category' => 'accessory', 'title' => 'Adaptador',
            'body_html' => '<p>Descripción original</p>', 'body_html_en' => '<p>English original</p>',
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->patch(route('imported-products.description', $product), [
                'body_html_it' => '<p onclick="alert(1)">Nuovo <strong>testo</strong></p><a href="javascript:alert(1)">Link</a>',
                'body_html' => '<p>Changed original</p>',
            ])->assertRedirect()->assertSessionHas('status', 'Descrizione aggiornata.');
        $fresh = $product->fresh();
        $this->assertSame('<p>Descripción original</p>', $fresh->body_html);
        $this->assertSame('<p>English original</p>', $fresh->body_html_en);
        $this->assertStringContainsString('<strong>testo</strong>', $fresh->body_html_it);
        $this->assertStringNotContainsString('onclick', $fresh->body_html_it);
        $this->assertStringNotContainsString('javascript:', $fresh->body_html_it);
        $this->patch(route('imported-products.description', $product), ['body_html_it' => null])->assertRedirect();
        $this->assertNull($product->fresh()->body_html_it);
        $this->get(route('imported-products.index'))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('products.data.0.body_html', '<p>Descripción original</p>')
            ->where('products.data.0.body_html_en', '<p>English original</p>'));
    }

    public function test_description_editor_rejects_invalid_input_and_non_admins(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'protected-description', 'category' => 'screen', 'title' => 'Radio']);
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->patchJson(route('imported-products.description', $product), ['body_html_it' => 'Changed'])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->patch(route('imported-products.description', $product), ['body_html_it' => ['invalid']])
            ->assertRedirect()->assertSessionHasErrors('body_html_it');
    }

    public function test_admin_can_set_variant_prices_and_refresh_the_minimum(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'prices', 'category' => 'screen', 'title' => 'Radio', 'price_min' => 100]);
        $a = $product->variants()->create(['title' => '2GB', 'price' => 100]);
        $b = $product->variants()->create(['title' => '4GB', 'price' => 200]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->patch(route('imported-products.variants', $product), ['variants' => [
                ['id' => $a->id, 'price' => 250.55],
                ['id' => $b->id, 'price' => 150],
            ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('250.55', $a->fresh()->price);
        $this->assertSame('150.00', $b->fresh()->price);
        $this->assertSame('150.00', $product->fresh()->price_min);
    }

    public function test_variant_prices_reject_foreign_variants_and_invalid_values_atomically(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'prices', 'category' => 'screen', 'title' => 'Radio', 'price_min' => 100]);
        $a = $product->variants()->create(['title' => '2GB', 'price' => 100]);
        $other = ConfiguratorProduct::create(['handle' => 'other', 'category' => 'screen', 'title' => 'Other']);
        $b = $other->variants()->create(['title' => '4GB', 'price' => 200]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([['id' => $b->id, 'price' => 150], ['id' => $a->id, 'price' => -1]] as $bad) {
            $this->patch(route('imported-products.variants', $product), ['variants' => [
                ['id' => $a->id, 'price' => 250], $bad,
            ]])->assertSessionHasErrors();
            $this->assertSame('100.00', $a->fresh()->price);
            $this->assertSame('200.00', $b->fresh()->price);
            $this->assertSame('100.00', $product->fresh()->price_min);
        }
    }

    public function test_non_admin_cannot_change_variant_prices(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'prices', 'category' => 'screen', 'title' => 'Radio']);
        $variant = $product->variants()->create(['title' => '2GB', 'price' => 100]);
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->patch(route('imported-products.variants', $product), ['variants' => [['id' => $variant->id, 'price' => 1]]])
            ->assertForbidden();
        $this->assertSame('100.00', $variant->fresh()->price);
    }

    public function test_admin_can_delete_an_imported_product_and_its_related_records(): void
    {
        $product = ConfiguratorProduct::create([
            'handle' => 'installation-test',
            'category' => 'installation',
            'title' => 'Installazione test',
        ]);
        $variant = $product->variants()->create([
            'title' => 'Default',
            'price' => 99,
        ]);
        $zone = InstallationZone::create([
            'name' => 'Zona test',
            'active' => true,
        ]);
        $zone->products()->create([
            'product_handle' => $product->handle,
        ]);

        $response = $this
            ->actingAs(User::factory()->create(['is_admin' => true]))
            ->delete(route('imported-products.destroy', $product));

        $response
            ->assertRedirect()
            ->assertSessionHas('status', 'Prodotto eliminato.');

        $this->assertModelMissing($product);
        $this->assertDatabaseMissing('configurator_variants', ['id' => $variant->id]);
        $this->assertDatabaseMissing('installation_zone_products', [
            'product_handle' => 'installation-test',
        ]);
    }

    public function test_non_admin_cannot_delete_an_imported_product(): void
    {
        $product = ConfiguratorProduct::create([
            'handle' => 'protected-product',
            'category' => 'screen',
            'title' => 'Prodotto protetto',
        ]);

        $this
            ->actingAs(User::factory()->create(['is_admin' => false]))
            ->delete(route('imported-products.destroy', $product))
            ->assertForbidden();

        $this->assertModelExists($product);
    }
    public function test_admin_can_edit_vehicle_and_multibrand_associations(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'vehicle-edit', 'category' => 'screen', 'title' => 'Radio', 'brand' => 'KIA', 'model' => 'STONIC', 'year_from' => 2020, 'year_to' => 2020]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->patch(route('imported-products.vehicle', $p), ['brand' => ' kia | hyundai ', 'model' => '1:stonic | 2:kona | 1:rio', 'year_from' => 2017, 'year_to' => 2026])
            ->assertRedirect()->assertSessionHasNoErrors();
        $fresh = $p->fresh();
        $this->assertSame('KIA | HYUNDAI', $fresh->brand);
        $this->assertSame('1:STONIC | 2:KONA | 1:RIO', $fresh->model);
        $this->assertSame(2026, (int) $fresh->year_to);
        $this->assertSame('Radio', $fresh->title);
        $this->assertSame([
            ['brand' => 'KIA', 'model' => 'STONIC'], ['brand' => 'HYUNDAI', 'model' => 'KONA'], ['brand' => 'KIA', 'model' => 'RIO'],
        ], app(\App\Services\VehicleImageResolver::class)->vehicleEntries($fresh->brand, $fresh->model));
    }

    public function test_vehicle_edit_rejects_invalid_years_and_brand_indices_and_non_admins(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'vehicle-protected', 'category' => 'screen', 'title' => 'Radio', 'brand' => 'KIA', 'model' => 'STONIC']);
        $data = ['brand' => 'KIA', 'model' => '1:STONIC', 'year_from' => 2020, 'year_to' => 2026];
        $this->actingAs(User::factory()->create(['is_admin' => false]))->patch(route('imported-products.vehicle', $p), $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->patch(route('imported-products.vehicle', $p), array_replace($data, ['year_to' => 2019]))->assertSessionHasErrors('year_to');
        $this->patch(route('imported-products.vehicle', $p), array_replace($data, ['model' => '2:KONA']))->assertSessionHasErrors('model');
        $this->assertSame('STONIC', $p->fresh()->model);
    }

    public function test_admin_can_choose_change_and_clear_dashboard_from_product_images(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'dashboard-editor', 'category' => 'screen', 'title' => 'Radio',
            'image_url' => '/storage/main.png', 'meta' => ['din' => '1DIN', 'gallery_images' => ['/storage/main.png', '/storage/dashboard.png']]]);
        $p->variants()->create(['title' => '8GB', 'price' => 200, 'image_url' => '/storage/variant.png']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get(route('imported-products.index'))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('products.data.0.dashboard_image_url', null)
            ->where('products.data.0.images', ['/storage/main.png', '/storage/dashboard.png', '/storage/variant.png']));
        $url = route('imported-products.dashboard-image', $p);
        $this->patch($url, ['image_url' => '/storage/dashboard.png'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([['url' => '/storage/dashboard.png', 'variant' => null]], $p->fresh()->meta['original_dashboard_images']);
        $this->assertSame('1DIN', $p->fresh()->meta['din']);
        $this->assertSame('/storage/main.png', $p->fresh()->image_url);
        $this->patch($url, ['image_url' => '/storage/variant.png'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('/storage/variant.png', $p->fresh()->meta['original_dashboard_images'][0]['url']);
        $this->patch($url, ['image_url' => null])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([], $p->fresh()->meta['original_dashboard_images']);
    }

    public function test_dashboard_selection_rejects_foreign_images_and_non_admins(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'dashboard-protected', 'category' => 'screen', 'title' => 'Radio', 'image_url' => '/storage/main.png']);
        $url = route('imported-products.dashboard-image', $p);
        $this->actingAs(User::factory()->create(['is_admin' => false]))->patch($url, ['image_url' => '/storage/main.png'])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->patch($url, ['image_url' => '/storage/another-product.png'])->assertSessionHasErrors('image_url');
        $this->assertNull($p->fresh()->meta);
    }

    public function test_export_by_import_date_uses_italian_day_and_current_catalog_after_drafts_are_deleted(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $p = ConfiguratorProduct::create(['handle' => 'date-export', 'category' => 'screen', 'title' => 'Radio Kia Stonic', 'body_html' => '<p>Descrizione attuale</p>',
            'brand' => 'KIA', 'model' => '1:STONIC', 'year_from' => 2017, 'year_to' => 2026, 'image_url' => '/storage/autogenesi/catalog/123/main.png',
            'meta' => ['din' => '1DIN', 'original_dashboard_images' => [['url' => '/storage/autogenesi/catalog/123/dashboard.png', 'variant' => null]], 'autogenesi' => ['imported_at' => '2026-11-04T23:30:00+00:00'], 'gallery_images' => ['/storage/autogenesi/catalog/123/main.png', '/storage/autogenesi/catalog/123/dashboard.png']]]);
        $p->variants()->create(['title' => '4GB 64GB', 'option_value' => '4GB 64GB', 'sku' => 'AE-123-1', 'price' => 299]);
        $p->variants()->create(['title' => '8GB 128GB', 'option_value' => '8GB 128GB', 'sku' => 'AE-123-2', 'price' => 399]);
        $p->update(['created_at' => '2025-01-01 12:00:00']);
        ConfiguratorProduct::create(['handle' => 'other-date', 'category' => 'screen', 'title' => 'Other', 'meta' => ['imported_at' => '2026-11-05T23:30:00+00:00']]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get(route('imported-products.export-shopify', ['date' => '2026-11-05']))->assertDownload('prodotti-importati-2026-11-05-shopify.xlsx');
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load(\Illuminate\Support\Facades\Storage::disk('local')->path('imported-products/exports/prodotti-importati-2026-11-05-shopify.xlsx'));
        $rows = $book->getSheetByName('Products')->toArray();
        $this->assertSame(['Products'], $book->getSheetNames());
        $headers = array_shift($rows);
        $rows = array_map(fn ($row) => array_combine($headers, $row), $rows);
        $this->assertCount(2, $rows);
        $this->assertSame(['date-export', 'date-export'], array_column($rows, 'Handle'));
        $this->assertSame('<p>Descrizione attuale</p>', $rows[0]['Body HTML']);
        $this->assertNull($rows[1]['Body HTML']);
        $this->assertSame(['299.00', '399.00'], array_column($rows, 'Variant Price'));
        $this->assertSame('1:STONIC', $rows[0][\App\Services\AutogenesiTemplateExport::MODEL]);
        $this->assertSame('dashboard.png', $rows[0][\App\Services\AutogenesiTemplateExport::DASHBOARD]);
        $this->assertSame('https://www.autoradioitaliano.it/storage/autogenesi/catalog/123/dashboard.png', $rows[1]['Image Src']);
        $this->assertNull($rows[1][\App\Services\AutogenesiTemplateExport::DASHBOARD]);
        $this->assertSame('https://www.autoradioitaliano.it/storage/autogenesi/catalog/123/main.png', $rows[0]['Image Src']);
        $this->assertNotContains(\App\Services\AutogenesiTemplateExport::SOURCE, $headers);
        $this->assertSame(['Active', 'Active'], array_column($rows, 'Status'));
        $this->assertDatabaseCount('autogenesi_products', 0);
    }

    public function test_date_export_validates_date_empty_results_and_admin_access(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $url = route('imported-products.export-shopify');
        $this->get($url.'?date=2026-02-30')->assertSessionHasErrors('date');
        $this->get($url.'?date=2026-11-05')->assertSessionHasErrors('export_date');
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles());
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get($url.'?date=2026-11-05')->assertForbidden();
    }

    public function test_supplier_link_is_recovered_from_aliexpress_handle_after_excel_import(): void
    {
        ConfiguratorProduct::create(['handle' => 'kia-stonic-ae-1005012798484336', 'category' => 'screen', 'title' => 'Radio', 'meta' => []]);
        ConfiguratorProduct::create(['handle' => 'generic-web-91234', 'category' => 'screen', 'title' => 'Other', 'meta' => ['autogenesi' => ['source_url' => 'https://supplier.example.com/radio']]]);
        ConfiguratorProduct::create(['handle' => 'no-supplier-link', 'category' => 'screen', 'title' => 'No link']);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('imported-products.index'))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('products.data', function ($rows) {
                $rows = collect($rows)->keyBy('handle');
                return $rows['kia-stonic-ae-1005012798484336']['source_url'] === 'https://es.aliexpress.com/item/1005012798484336.html'
                    && $rows['generic-web-91234']['source_url'] === 'https://supplier.example.com/radio'
                    && $rows['no-supplier-link']['source_url'] === null;
            }));
    }

    public function test_admin_can_edit_spanish_italian_and_english_titles_together(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'three-languages', 'category' => 'screen', 'title' => 'Título anterior']);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->patch(route('imported-products.titles', $p), [
            'title' => 'Autoradio para Volvo XC40', 'title_it' => 'Autoradio per Volvo XC40', 'title_en' => 'Car radio for Volvo XC40',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Autoradio para Volvo XC40', $p->fresh()->title);
        $this->assertSame('Autoradio per Volvo XC40', $p->fresh()->title_it);
        $this->assertSame('Car radio for Volvo XC40', $p->fresh()->title_en);
        $this->patch(route('imported-products.titles', $p), ['title' => '', 'title_it' => '', 'title_en' => ''])->assertSessionHasErrors('title');
        $this->assertSame('Autoradio para Volvo XC40', $p->fresh()->title);
    }

    public function test_import_date_filter_combines_with_search_and_category_using_italian_day(): void
    {
        foreach ([['first', 'screen', 'Radio Kia', '2026-11-05T23:30:00+00:00'], ['second', 'screen', 'Radio Kia', '2026-11-05T10:00:00+00:00'], ['third', 'camera', 'Camera Kia', '2026-11-05T23:30:00+00:00']] as [$handle, $category, $title, $date]) {
            ConfiguratorProduct::create(['handle' => $handle, 'category' => $category, 'title' => $title, 'meta' => ['imported_at' => $date]]);
        }
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get(route('imported-products.index', ['date' => '2026-11-06', 'category' => 'screen', 'search' => 'Kia']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('filters.date', '2026-11-06')->has('products.data', 1)->where('products.data.0.handle', 'first')->where('products.total', 1));
        $this->get(route('imported-products.index'))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('products.data', 3));
        $this->get(route('imported-products.index', ['date' => 'invalid']))->assertSessionHasErrors('date');
    }

}
