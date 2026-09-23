<?php

namespace Tests\Feature;

use App\Http\Middleware\BlockOutsideEurope;
use App\Models\ConfiguratorProduct;
use App\Services\ConfiguratorCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ConfiguratorDashcamTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_dashcams_are_available_in_both_modes_and_excluded_from_screens(): void
    {
        $product = ConfiguratorProduct::create([
            'handle' => 'dashcam-test', 'category' => 'screen', 'title' => 'Dashcam test',
            'model' => 'Universal', 'price_min' => 59,
            'meta' => ['type' => 'DASHCAM', 'din' => '2DIN'],
        ]);
        $variant = $product->variants()->create(['title' => '32 GB', 'price' => 59, 'shopify_variant_id' => '123']);
        foreach (['universal', 'specific'] as $mode) {
            $this->withoutMiddleware(BlockOutsideEurope::class)
                ->getJson('/configurator/catalog/cameras?mode='.$mode)
                ->assertOk()->assertJsonCount(1, 'products')
                ->assertJsonPath('products.0.isDashcam', true)
                ->assertJsonPath('products.0.variants.0.id', $variant->id);
        }
        $this->getJson('/configurator/catalog/universal-screens?din=2DIN')
            ->assertOk()->assertJsonCount(0, 'products');
    }

    public function test_all_dashcams_are_visible_regardless_of_selected_vehicle(): void
    {
        foreach (['BMW', 'FIAT'] as $brand) {
            ConfiguratorProduct::create([
                'handle' => 'dashcam-'.strtolower($brand), 'category' => 'camera',
                'title' => 'Dashcam '.$brand, 'brand' => $brand, 'model' => 'Other',
                'year_from' => 2020, 'year_to' => 2024, 'price_min' => 59,
                'meta' => ['type' => 'DASHCAM'],
            ]);
        }
        $this->withoutMiddleware(BlockOutsideEurope::class)
            ->getJson('/configurator/catalog/cameras?mode=specific&brand=KIA&model=Picanto&year=2015')
            ->assertOk()->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.isDashcam', true)
            ->assertJsonPath('products.1.isDashcam', true);
    }

    public function test_import_recognizes_dashcam_type_without_vehicle_metadata(): void
    {
        $csv = <<<'CSV'
Title,Image Src,Option1 Value,Handle,ID,Variant ID,Type,Variant Price,Variant SKU,Price / Italia,Price / Resto del Mondo,Price / USA-CANADA,Price / spagna,Metafield: custom.radio_type [single_line_text_field],Metafield: custom.altavoces [single_line_text_field],Metafield: custom.modello_auto [single_line_text_field],Metafield: custom.anno [single_line_text_field],Metafield: shopify.vehicle-coaxial-speaker-nominal-size [list.metaobject_reference]
Dashcam USB,,Default,dashcam-import,123,123456,DASHCAM,59,DVR,59,59,59,59,,,,,
CSV;
        app(ConfiguratorCsvImporter::class)->import(UploadedFile::fake()->createWithContent('dashcam.csv', $csv));
        $product = ConfiguratorProduct::where('handle', 'dashcam-import')->firstOrFail();
        $this->assertSame('camera', $product->category);
        $this->assertSame('DASHCAM', $product->meta['type']);
    }
}
