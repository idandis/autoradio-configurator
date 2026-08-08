<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\User;
use App\Services\ConfiguratorCsvImporter;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ConfiguratorImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_reports_all_missing_essential_headers_without_changing_the_catalog(): void
    {
        ConfiguratorProduct::create([
            'handle' => 'existing-product',
            'category' => 'screen',
            'title' => 'Prodotto esistente',
        ]);

        $csv = <<<'CSV'
Product Title,Variant Price
Prodotto senza campi,99.00
CSV;

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->post(route('dashboard.import'), [
                'catalog' => UploadedFile::fake()->createWithContent('invalid.csv', $csv),
                'mode' => 'replace',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('catalog');

        $this->assertDatabaseHas('configurator_products', ['handle' => 'existing-product']);
    }

    public function test_csv_import_creates_configurator_products(): void
    {
        $csv = <<<'CSV'
Handle,Title,Type,Tags,Option1 Name,Variant Option1 Value,Variant ID,Variant SKU,Variant Price,Image Src,CAM (product.metafields.custom.cam),DIN (product.metafields.custom.dimensioni_schermo),PULGADAS (product.metafields.custom.pulgadas),MARCA DE COCHE (product.metafields.custom.radio_type),Product.custom.modello_auto,Product.custom.anno
toyota-yaris-screen,Toyota Yaris 2018-2020 – Pantalla Android 9",Radio AM/FM,"2DIN,TOYOTA",Variantes,4core 2-32GB,1111111111,YARIS-32,199.00,https://example.com/screen-1.jpg,,2DIN,9,TOYOTA,Yaris,2018-2020
toyota-yaris-screen,,,,"",8core 4-64GB,2222222222,YARIS-64,269.00,https://example.com/screen-2.jpg,,,,,Yaris,
camara-trasera-estandar,Camara trasera estandar,CAM,,,Default,3333333333,CAM-STD,21.00,https://example.com/camera.jpg,,,,
instalacion-base-de-pantalla-camara-trasera,Instalación base de Pantalla + Camara Trasera en Gran Canaria,,,Default,4444444444,INSTALL,167.00,https://example.com/install.jpg,,,,
CSV;

        $file = UploadedFile::fake()->createWithContent('catalog.csv', $csv);

        $this->actingAs(User::factory()->create())
            ->post(route('dashboard.import'), [
                'catalog' => $file,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('configurator_products', 3);
        $this->assertEquals(2, ConfiguratorProduct::where('category', 'screen')->first()->variants()->count());
        $this->assertSame('Yaris', ConfiguratorProduct::where('category', 'screen')->first()->model);
        $this->assertSame(2018, ConfiguratorProduct::where('category', 'screen')->first()->year_from);
        $this->assertSame(2020, ConfiguratorProduct::where('category', 'screen')->first()->year_to);
        $this->assertDatabaseHas('configurator_variants', [
            'sku' => 'YARIS-32',
            'shopify_variant_id' => '1111111111',
            'title' => '4core 2-32GB',
            'option_value' => '4core 2-32GB',
        ]);
        $this->assertDatabaseHas('configurator_variants', [
            'sku' => 'YARIS-64',
            'option_value' => '8core 4-64GB',
        ]);
    }

    public function test_second_variant_option_is_exposed_as_a_color_selector_without_duplicate_configurations(): void
    {
        $csv = <<<'CSV'
Handle,Title,Type,Tags,Option1 Name,Variant Option1 Value,Variant Option2 Value,Variant ID,Variant SKU,Variant Price,Image Src,CAM (product.metafields.custom.cam),DIN (product.metafields.custom.dimensioni_schermo),PULGADAS (product.metafields.custom.pulgadas),MARCA DE COCHE (product.metafields.custom.radio_type),Product.custom.modello_auto,Product.custom.anno
mercedes-screen,Mercedes W203,Radio AM/FM,MERCEDES,Variantes,8core 8GB 256GB,Gris,1111111111,GRAY,499.00,https://example.com/screen.jpg,,,9,MERCEDES,Clase C,2002-2005
mercedes-screen,,,,,8core 8GB 256GB,Marrón,2222222222,BROWN,499.00,https://example.com/screen.jpg,,,,,Clase C,
CSV;

        app(ConfiguratorCsvImporter::class)->import(
            UploadedFile::fake()->createWithContent('colors.csv', $csv)->getPathname(),
        );

        $variants = ConfiguratorProduct::where('handle', 'mercedes-screen')->firstOrFail()->variants;
        $this->assertCount(2, $variants);
        $this->assertSame(['Gris', 'Marrón'], $variants->pluck('meta')->pluck('option2')->all());

        $this->get('/configurator')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Configurator')
            ->has('vehicles', 1)
            ->has('vehicles.0.variants', 1)
            ->where('vehicles.0.variants.0.title', '8core 8GB 256GB')
            ->has('vehicles.0.variants.0.colorOptions', 2)
            ->where('vehicles.0.variants.0.colorOptions.0.color', 'Gris')
            ->where('vehicles.0.variants.0.colorOptions.1.color', 'Marrón')
        );
    }

    public function test_missing_color_column_is_enriched_from_public_shopify_variants(): void
    {
        Http::fake([
            'https://www.autoradiocanario.com/products/mercedes-public-colors.js' => Http::response([
                'variants' => [
                    ['id' => 1111111111, 'option1' => '8core 8GB', 'option2' => 'Gris', 'option3' => null],
                    ['id' => 2222222222, 'option1' => '8core 8GB', 'option2' => 'Marrón', 'option3' => null],
                ],
            ]),
        ]);

        $csv = <<<'CSV'
Handle,Title,Type,Tags,Option1 Name,Variant Option1 Value,Variant ID,Variant SKU,Variant Price,Image Src,CAM (product.metafields.custom.cam),DIN (product.metafields.custom.dimensioni_schermo),PULGADAS (product.metafields.custom.pulgadas),MARCA DE COCHE (product.metafields.custom.radio_type),Product.custom.modello_auto,Product.custom.anno
mercedes-public-colors,Mercedes W203,Radio AM/FM,MERCEDES,Variantes,8core 8GB,1111111111,GRAY,499.00,https://example.com/screen.jpg,,,9,MERCEDES,Clase C,2002-2005
mercedes-public-colors,,,,,8core 8GB,2222222222,BROWN,499.00,https://example.com/screen.jpg,,,,,Clase C,
CSV;

        app(ConfiguratorCsvImporter::class)->import(
            UploadedFile::fake()->createWithContent('colors-without-option2.csv', $csv),
        );

        $variants = ConfiguratorProduct::where('handle', 'mercedes-public-colors')->firstOrFail()->variants;
        $this->assertSame(['Gris', 'Marrón'], $variants->pluck('meta')->pluck('option2')->all());
        Http::assertSentCount(1);
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

        $csv = <<<'CSV'
Product Type,Collection Titles,Product Tags,Product Title,Product Handle,Product Image,Variant Id,Variant Title,Variant Option1 Value,Product.custom.modello_auto,Product.custom.anno
CAM,Cámaras,"cam Trasera,HYUNDAI",Cámara Trasera HD 1080P AHD/CVBS con Visión Nocturna para Hyundai i20,camara-trasera-hd-1080p-ahd-cvbs-con-vision-nocturna-para-hyundai-i20,"https://example.com/camera.jpg, https://example.com/camera-2.jpg",57508028350808,Cámara Trasera HD 1080P AHD/CVBS con Visión Nocturna para Hyundai i20,Default,
Radio AM/FM,TOYOTA,"2DIN,9'',TOYOTA","Toyota Corolla E120 2000-2012 – Autoradio Android 12 QLED 9"" con CarPlay Inalámbrico, GPS y 4G",toyota-corolla-e120-2000-2012-autoradio-android-12-qled-9,"https://example.com/screen.jpg, https://example.com/screen-2.jpg",57494413705560,"Toyota Corolla E120 2000-2012 – Autoradio Android 12 QLED 9"" con CarPlay Inalámbrico, GPS y 4G",4core 2-32GB,Corolla E120,2000-2012
CSV;

        $file = UploadedFile::fake()->createWithContent('catalog.csv', $csv);

        $this->actingAs(User::factory()->create())
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

    public function test_each_import_completely_replaces_the_previous_dataset(): void
    {
        $user = User::factory()->create();
        $headers = 'Product Type,Product Title,Product Handle,Variant Id,Variant Price,Variant Option1 Value,Product.custom.radio_type,Product.custom.modello_auto,Product.custom.anno';

        $firstCsv = $headers."\n".
            'Radio AM/FM,Toyota Yaris 2018-2020,old-screen,111,199.00,4core 2-32GB,TOYOTA,Yaris,2018-2020';
        $secondCsv = $headers."\n".
            'Radio AM/FM,Ford Focus 2019-2022,new-screen,222,249.00,8core 4-64GB,FORD,Focus,2019-2022';

        $this->actingAs($user)->post(route('dashboard.import'), [
            'catalog' => UploadedFile::fake()->createWithContent('first.csv', $firstCsv),
        ])->assertRedirect();

        $this->assertDatabaseHas('configurator_products', ['handle' => 'old-screen']);

        $this->actingAs($user)->post(route('dashboard.import'), [
            'catalog' => UploadedFile::fake()->createWithContent('second.csv', $secondCsv),
        ])->assertRedirect();

        $this->assertDatabaseCount('configurator_products', 1);
        $this->assertDatabaseCount('configurator_variants', 1);
        $this->assertDatabaseMissing('configurator_products', ['handle' => 'old-screen']);
        $this->assertDatabaseMissing('configurator_variants', ['shopify_variant_id' => '111']);
        $this->assertDatabaseHas('configurator_products', [
            'handle' => 'new-screen',
            'model' => 'Focus',
        ]);
        $this->assertDatabaseHas('configurator_variants', [
            'shopify_variant_id' => '222',
            'option_value' => '8core 4-64GB',
        ]);
    }

    public function test_add_mode_preserves_other_products_and_updates_matching_handles(): void
    {
        $user = User::factory()->create();
        $headers = 'Product Type,Product Title,Product Handle,Variant Id,Variant Price,Variant Option1 Value,Product.custom.radio_type,Product.custom.modello_auto,Product.custom.anno';

        $initialCsv = $headers."\n".
            'Radio AM/FM,Audi A4,audi-a4,111,299.00,Vecchia variante,AUDI,A4,2000-2009'."\n".
            'Radio AM/FM,Ford Focus,ford-focus,222,249.00,Focus variante,FORD,Focus,2010-2015';
        $updateCsv = $headers."\n".
            'Radio AM/FM,Audi A4 aggiornato,audi-a4,333,349.00,Nuova variante,AUDI,A4,2000-2009';

        $this->actingAs($user)->post(route('dashboard.import'), [
            'catalog' => UploadedFile::fake()->createWithContent('initial.csv', $initialCsv),
            'mode' => 'replace',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('dashboard.import'), [
            'catalog' => UploadedFile::fake()->createWithContent('update.csv', $updateCsv),
            'mode' => 'add',
        ])->assertRedirect();

        $this->assertDatabaseCount('configurator_products', 2);
        $this->assertDatabaseHas('configurator_products', [
            'handle' => 'ford-focus',
        ]);
        $this->assertDatabaseHas('configurator_products', [
            'handle' => 'audi-a4',
            'title' => 'Audi A4 aggiornato',
        ]);
        $this->assertDatabaseMissing('configurator_variants', [
            'shopify_variant_id' => '111',
        ]);
        $this->assertDatabaseHas('configurator_variants', [
            'shopify_variant_id' => '333',
            'option_value' => 'Nuova variante',
        ]);
    }

    public function test_camera_category_requires_product_type_cam(): void
    {
        $csv = <<<'CSV'
Product Type,Product Title,Product Handle,Product Tags,Variant Id,Variant Price,Variant Option1 Value
CAM,Camera valida,camera-valid,accessorio,111,49.00,Default
ACCESSORIO,Camera non valida,camera-non-valid,"camara,cámara",222,39.00,Default
CSV;

        $this->actingAs(User::factory()->create())
            ->post(route('dashboard.import'), [
                'catalog' => UploadedFile::fake()->createWithContent('cameras.csv', $csv),
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('configurator_products', 1);
        $this->assertDatabaseHas('configurator_products', [
            'handle' => 'camera-valid',
            'category' => 'camera',
        ]);
        $this->assertDatabaseMissing('configurator_products', [
            'handle' => 'camera-non-valid',
        ]);
    }

    public function test_import_supports_xlsx_and_xls_files(): void
    {
        foreach (['xlsx', 'xls'] as $extension) {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray([
                [
                    'Product Type',
                    'Product Title',
                    'Product Handle',
                    'Variant Id',
                    'Variant Price',
                    'Variant Option1 Value',
                    'Product.custom.radio_type',
                    'Product.custom.modello_auto',
                    'Product.custom.anno',
                ],
                [
                    'Radio AM/FM',
                    'Audi A4',
                    'audi-a4-'.$extension,
                    '123456',
                    '299.00',
                    '8core 4-64GB',
                    'AUDI',
                    'A4',
                    '2000-2009',
                ],
            ]);

            $path = sys_get_temp_dir().'/configurator-'.uniqid().'.'.$extension;
            $writer = $extension === 'xlsx' ? new Xlsx($spreadsheet) : new Xls($spreadsheet);
            $writer->save($path);

            try {
                app(ConfiguratorCsvImporter::class)->import($path);
            } finally {
                @unlink($path);
                $spreadsheet->disconnectWorksheets();
            }

            $this->assertDatabaseCount('configurator_products', 1);
            $this->assertDatabaseHas('configurator_products', [
                'handle' => 'audi-a4-'.$extension,
                'brand' => 'AUDI',
                'model' => 'A4',
                'year_from' => 2000,
                'year_to' => 2009,
            ]);
            $this->assertDatabaseHas('configurator_variants', [
                'option_value' => '8core 4-64GB',
                'price' => 299,
            ]);
        }
    }

    public function test_import_supports_new_matrixify_style_headers_and_spanish_price(): void
    {
        $csv = <<<'CSV'
Title,Variant Cost,Image Src,Option1 Value,Handle,ID,Variant ID,Price / Italia,Price / Resto del Mondo,Price / USA-CANADA,Price / spagna,Metafield: custom.radio_type [single_line_text_field],Metafield: custom.modello_auto [single_line_text_field],Metafield: custom.anno [single_line_text_field],Metafield: shopify.vehicle-coaxial-speaker-nominal-size [list.metaobject_reference]
Audi A4 Autoradio,180.00,https://example.com/audi-a4.jpg,8core 4-64GB,audi-a4-radio,987654,57508028350808,319.00,329.00,349.00,299.00,AUDI,A4,2000-2009,gid://shopify/Metaobject/123
CSV;

        $this->actingAs(User::factory()->create())
            ->post(route('dashboard.import'), [
                'catalog' => UploadedFile::fake()->createWithContent('new-export.csv', $csv),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('configurator_products', [
            'handle' => 'audi-a4-radio',
            'category' => 'screen',
            'brand' => 'AUDI',
            'model' => 'A4',
            'year_from' => 2000,
            'year_to' => 2009,
            'price_min' => 299,
        ]);
        $this->assertDatabaseHas('configurator_variants', [
            'shopify_variant_id' => '57508028350808',
            'option_value' => '8core 4-64GB',
            'price' => 299,
            'image_url' => 'https://example.com/audi-a4.jpg',
        ]);
    }

    public function test_import_creates_speakers_with_nominal_sizes(): void
    {
        $csv = <<<'CSV'
Type,Title,Image Src,Option1 Value,Handle,Variant ID,Price / spagna,Metafield: shopify.vehicle-coaxial-speaker-nominal-size [list.metaobject_reference],Metafield: custom.altavoces [single_line_text_field]
ALTAVOCES,Altoparlanti coassiali,https://example.com/speaker.jpg,Coppia 300W,speaker-coaxial,57508028350000,89.90,"[""6.5 pulgadas"",""165 mm""]","Altavoces completos | Kit de altavoces | Subwoofer | Tweeter"
ALTAVOCES,,https://example.com/speaker-detail.jpg,,speaker-coaxial,,,
CSV;

        $this->actingAs(User::factory()->create())
            ->post(route('dashboard.import'), [
                'catalog' => UploadedFile::fake()->createWithContent('speakers.csv', $csv),
            ])
            ->assertRedirect();

        $speaker = ConfiguratorProduct::where('category', 'speaker')->firstOrFail();

        $this->assertSame(['6.5 pulgadas', '165 mm'], $speaker->meta['speaker_sizes']);
        $this->assertSame(
            ['Altavoces completos', 'Kit de altavoces', 'Subwoofer', 'Tweeter'],
            $speaker->meta['speaker_categories'],
        );
        $this->assertSame(1, $speaker->variants()->count());
        $this->assertDatabaseHas('configurator_variants', [
            'shopify_variant_id' => '57508028350000',
            'option_value' => 'Coppia 300W',
            'price' => 89.90,
        ]);
    }

    public function test_import_parses_installation_location_type_and_prefers_spanish_price(): void
    {
        $csv = <<<'CSV'
Title,Image Src,Option1 Value,Handle,ID,Variant ID,Type,Variant Price,Price / Italia,Price / Resto del Mondo,Price / USA-CANADA,Price / spagna,Metafield: custom.radio_type [single_line_text_field],Metafield: custom.modello_auto [single_line_text_field],Metafield: custom.installazione [single_line_text_field],Metafield: custom.anno [single_line_text_field],Metafield: shopify.vehicle-coaxial-speaker-nominal-size [list.metaobject_reference]
Installazione Madrid,,Default,installazione-madrid,123,456,SERVICIO,199.00,190.00,195.00,210.00,167.00,,,"Madrid,pantalla+camara",,
CSV;

        $this->actingAs(User::factory()->create())
            ->post(route('dashboard.import'), [
                'catalog' => UploadedFile::fake()->createWithContent('installations.csv', $csv),
            ])
            ->assertRedirect();

        $installation = ConfiguratorProduct::where('category', 'installation')->firstOrFail();

        $this->assertSame('screen_camera', $installation->subtype);
        $this->assertSame('Madrid', $installation->meta['installation']['location']);
        $this->assertDatabaseHas('configurator_variants', [
            'shopify_variant_id' => '456',
            'price' => 167,
        ]);
    }

    public function test_import_recognizes_speaker_and_screen_installation(): void
    {
        $csv = <<<'CSV'
Title,Handle,Variant ID,Type,Variant Price,Metafield: custom.installazione [single_line_text_field]
Gran Canaria - Instalación Pantalla + 2 altavoces,instalacion-sonido-gran-canaria,56401283252568,INSTALLAZIONE,231.00,"Gran Canaria, Altavoces+Pantalla"
CSV;

        $this->actingAs(User::factory()->create())
            ->post(route('dashboard.import'), [
                'catalog' => UploadedFile::fake()->createWithContent('speaker-installation.csv', $csv),
            ])
            ->assertRedirect();

        $installation = ConfiguratorProduct::where('handle', 'instalacion-sonido-gran-canaria')->firstOrFail();

        $this->assertSame('Gran Canaria - Instalación Pantalla + 2 altavoces', $installation->title);
        $this->assertSame('speaker_screen', $installation->subtype);
        $this->assertSame('Gran Canaria', $installation->meta['installation']['location']);
    }

    public function test_import_reads_quoted_title_header_after_utf8_bom(): void
    {
        $csv = "\u{FEFF}".<<<'CSV'
"Title",Handle,Variant ID,Type,Variant Price,Metafield: custom.installazione [single_line_text_field]
"Gran Canaria - Instalación Pantalla",instalacion-bom-title,123456,INSTALLAZIONE,107.00,"Gran Canaria, Pantalla"
CSV;

        $this->actingAs(User::factory()->create())
            ->post(route('dashboard.import'), [
                'catalog' => UploadedFile::fake()->createWithContent('bom-title.csv', $csv),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('configurator_products', [
            'handle' => 'instalacion-bom-title',
            'title' => 'Gran Canaria - Instalación Pantalla',
        ]);
    }
}
