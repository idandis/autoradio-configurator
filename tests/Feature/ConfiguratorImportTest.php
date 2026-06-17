<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ConfiguratorImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_import_creates_configurator_products(): void
    {
        $csv = <<<CSV
Handle,Title,Type,Tags,Option1 Name,Option1 Value,Variant ID,Variant SKU,Variant Price,Image Src,CAM (product.metafields.custom.cam),DIN (product.metafields.custom.dimensioni_schermo),PULGADAS (product.metafields.custom.pulgadas),MARCA DE COCHE (product.metafields.custom.radio_type)
toyota-yaris-screen,Toyota Yaris 2018-2020 – Pantalla Android 9",Radio AM/FM,"2DIN,TOYOTA",Variantes,4core 2-32GB,1111111111,YARIS-32,199.00,https://example.com/screen-1.jpg,,2DIN,9,TOYOTA
toyota-yaris-screen,,,,"",8core 4-64GB,2222222222,YARIS-64,269.00,https://example.com/screen-2.jpg,,,,
camara-trasera-estandar,Camara trasera estandar,CAM,,,Default,3333333333,CAM-STD,21.00,https://example.com/camera.jpg,,,,
instalacion-base-de-pantalla-camara-trasera,Instalación base de Pantalla + Camara Trasera en Gran Canaria,,,Default,4444444444,INSTALL,167.00,https://example.com/install.jpg,,,,
CSV;

        $file = UploadedFile::fake()->createWithContent('catalog.csv', $csv);

        $this->actingAs(\App\Models\User::factory()->create())
            ->post(route('dashboard.import'), [
                'catalog' => $file,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('configurator_products', 3);
        $this->assertEquals(2, ConfiguratorProduct::where('category', 'screen')->first()->variants()->count());
        $this->assertDatabaseHas('configurator_variants', [
            'sku' => 'YARIS-32',
            'shopify_variant_id' => '1111111111',
        ]);
    }

    public function test_csv_import_supports_new_export_format(): void
    {
        $this->mock(ShopifyService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('getVariantsByIds')
                ->once()
                ->andReturn([
                    '57508028350808' => [
                        'variant_id' => '57508028350808',
                        'variant_title' => 'Cámara Trasera HD 1080P AHD/CVBS',
                        'sku' => 'CAM-HD-HYU',
                        'price' => '49.90',
                        'image_url' => 'https://example.com/camera.jpg',
                        'product_title' => 'Cámara Trasera HD 1080P AHD/CVBS con Visión Nocturna para Hyundai i20',
                        'product_handle' => 'camara-trasera-hd-1080p-ahd-cvbs-con-vision-nocturna-para-hyundai-i20',
                        'product_type' => 'CAM',
                        'product_tags' => ['cam Trasera', 'HYUNDAI'],
                        'featured_image' => 'https://example.com/camera-featured.jpg',
                    ],
                    '57494413705560' => [
                        'variant_id' => '57494413705560',
                        'variant_title' => '4core 2-32GB',
                        'sku' => 'COROLLA-32',
                        'price' => '199.00',
                        'image_url' => 'https://example.com/screen.jpg',
                        'product_title' => 'Toyota Corolla E120 2000-2012 – Autoradio Android 12 QLED 9"',
                        'product_handle' => 'toyota-corolla-e120-2000-2012-autoradio-android-12-qled-9',
                        'product_type' => 'Radio AM/FM',
                        'product_tags' => ['2DIN', "9''", 'TOYOTA'],
                        'featured_image' => 'https://example.com/screen-featured.jpg',
                    ],
                ]);
        });

        $csv = <<<CSV
Product Type,Collection Titles,Product Tags,Product Title,Product Handle,Product Image,Variant Id,Variant Title
CAM,Cámaras,"cam Trasera,HYUNDAI",Cámara Trasera HD 1080P AHD/CVBS con Visión Nocturna para Hyundai i20,camara-trasera-hd-1080p-ahd-cvbs-con-vision-nocturna-para-hyundai-i20,"https://example.com/camera.jpg, https://example.com/camera-2.jpg",57508028350808,Cámara Trasera HD 1080P AHD/CVBS con Visión Nocturna para Hyundai i20
Radio AM/FM,TOYOTA,"2DIN,9'',TOYOTA","Toyota Corolla E120 2000-2012 – Autoradio Android 12 QLED 9"" con CarPlay Inalámbrico, GPS y 4G",toyota-corolla-e120-2000-2012-autoradio-android-12-qled-9,"https://example.com/screen.jpg, https://example.com/screen-2.jpg",57494413705560,"Toyota Corolla E120 2000-2012 – Autoradio Android 12 QLED 9"" con CarPlay Inalámbrico, GPS y 4G"
CSV;

        $file = UploadedFile::fake()->createWithContent('catalog.csv', $csv);

        $this->actingAs(\App\Models\User::factory()->create())
            ->post(route('dashboard.import'), [
                'catalog' => $file,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('configurator_products', 2);
        $this->assertDatabaseHas('configurator_products', [
            'handle' => 'toyota-corolla-e120-2000-2012-autoradio-android-12-qled-9',
            'brand' => 'TOYOTA',
            'model' => 'Corolla E120',
        ]);
        $this->assertDatabaseHas('configurator_variants', [
            'sku' => 'COROLLA-32',
            'shopify_variant_id' => '57494413705560',
            'title' => '4core 2-32GB',
        ]);
    }
}
