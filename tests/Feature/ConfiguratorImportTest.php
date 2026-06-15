<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ConfiguratorImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_import_creates_configurator_products(): void
    {
        $csv = <<<CSV
Handle,Title,Type,Tags,Option1 Name,Option1 Value,Variant SKU,Variant Price,Image Src,CAM (product.metafields.custom.cam),DIN (product.metafields.custom.dimensioni_schermo),PULGADAS (product.metafields.custom.pulgadas),MARCA DE COCHE (product.metafields.custom.radio_type)
toyota-yaris-screen,Toyota Yaris 2018-2020 – Pantalla Android 9",Radio AM/FM,"2DIN,TOYOTA",Variantes,4core 2-32GB,YARIS-32,199.00,https://example.com/screen-1.jpg,,2DIN,9,TOYOTA
toyota-yaris-screen,,,,"",8core 4-64GB,YARIS-64,269.00,https://example.com/screen-2.jpg,,,,
camara-trasera-estandar,Camara trasera estandar,CAM,,,Default,CAM-STD,21.00,https://example.com/camera.jpg,,,,
instalacion-base-de-pantalla-camara-trasera,Instalación base de Pantalla + Camara Trasera en Gran Canaria,,,,INSTALL,167.00,https://example.com/install.jpg,,,,
CSV;

        $file = UploadedFile::fake()->createWithContent('catalog.csv', $csv);

        $this->actingAs(\App\Models\User::factory()->create())
            ->post(route('dashboard.import'), [
                'catalog' => $file,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('configurator_products', 3);
        $this->assertEquals(2, ConfiguratorProduct::where('category', 'screen')->first()->variants()->count());
    }
}
