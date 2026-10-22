<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Services\VehicleImageGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleImageGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_october_22_volkswagen_images_clear_missing_vehicle_tasks(): void
    {
        $resolver = app(\App\Services\VehicleImageResolver::class);
        foreach (['Golf Plus' => 'golf-plus', 'Eos' => 'eos', 'Transporter T5' => 'transporter-t5'] as $model => $slug) {
            foreach (range(2003, 2013) as $year) {
                $this->assertSame("volkswagen-{$slug}-2003-2013.webp", $resolver->resolveFilename('VOLKSWAGEN', $model, $year));
            }
            ConfiguratorProduct::create([
                'handle' => $slug, 'category' => 'screen', 'title' => 'Radio',
                'brand' => 'VOLKSWAGEN', 'model' => $model, 'year_from' => 2003, 'year_to' => 2013,
            ]);
        }
        $this->assertCount(0, app(VehicleImageGenerator::class)->missingVehicles());
    }

    public function test_mazda_cx5_and_volkswagen_up_images_cover_requested_years(): void
    {
        $resolver = app(\App\Services\VehicleImageResolver::class);
        foreach ([['MAZDA', 'CX-5', 'mazda-cx-5-2017-2023.webp'], ['VOLKSWAGEN', 'Up', 'volkswagen-up-2017-2023.webp']] as [$brand, $model, $image]) {
            foreach (range(2017, 2023) as $year) {
                $this->assertSame($image, $resolver->resolveFilename($brand, $model, $year));
            }
            ConfiguratorProduct::create([
                'handle' => strtolower($brand).'-new-image', 'category' => 'screen', 'title' => 'Radio',
                'brand' => $brand, 'model' => $model, 'year_from' => 2017, 'year_to' => 2023,
            ]);
        }
        $this->assertCount(0, app(VehicleImageGenerator::class)->missingVehicles());
    }

    public function test_post_import_vehicle_images_cover_each_body_generation(): void
    {
        $resolver = app(\App\Services\VehicleImageResolver::class);
        $files = $resolver->imageFilenames();
        foreach (range(2017, 2025) as $year) {
            $this->assertSame($year < 2023 ? 'volkswagen-amarok-2017-2025.webp' : 'volkswagen-amarok-2023-2025.webp',
                $resolver->resolveFilename('VOLKSWAGEN', 'Amarok', $year, $files));
            $this->assertSame('man-van-2017-2025.webp', $resolver->resolveFilename('MAN', 'Van', $year, $files));
        }
        foreach ([['VOLKSWAGEN', 'Amarok'], ['MAN', 'Van']] as [$brand, $model]) {
            ConfiguratorProduct::create([
                'handle' => strtolower($brand).'-image-test', 'category' => 'screen', 'title' => 'Radio',
                'brand' => $brand, 'model' => $model, 'year_from' => 2017, 'year_to' => 2025,
            ]);
        }
        $this->assertCount(0, app(VehicleImageGenerator::class)->missingVehicles());
    }

    public function test_it_reports_each_missing_vehicle_from_a_multibrand_product(): void
    {
        ConfiguratorProduct::create([
            'handle' => 'multibrand-screen',
            'category' => 'screen',
            'title' => 'Multibrand screen',
            'brand' => 'TEST BRAND A | TEST BRAND B',
            'model' => '1:Model A | 2:Model B',
            'year_from' => 2020,
            'year_to' => 2021,
        ]);

        $missing = app(VehicleImageGenerator::class)->missingVehicles();

        $this->assertCount(2, $missing);
        $this->assertEqualsCanonicalizing(
            ['TEST BRAND A', 'TEST BRAND B'],
            $missing->pluck('brand')->all(),
        );
    }

    public function test_it_reports_invalid_indexed_multibrand_data_separately(): void
    {
        ConfiguratorProduct::create([
            'handle' => 'broken-multibrand-screen',
            'category' => 'screen',
            'title' => 'Broken multibrand screen',
            'brand' => 'CITROEN',
            'model' => '1:208 | 2:C4',
            'year_from' => 2014,
            'year_to' => 2018,
        ]);

        $generator = app(VehicleImageGenerator::class);

        $this->assertSame([], $generator->missingVehicles()->all());
        $this->assertSame(
            ['broken-multibrand-screen'],
            $generator->unresolvedVehicleProducts()->pluck('handle')->all(),
        );
    }

    public function test_it_does_not_treat_universal_screens_as_invalid_vehicle_data(): void
    {
        ConfiguratorProduct::create([
            'handle' => 'universal-screen',
            'category' => 'screen',
            'title' => 'Universal screen',
            'brand' => 'TOYOTA',
            'model' => 'Universal',
        ]);

        $this->assertSame([], app(VehicleImageGenerator::class)
            ->unresolvedVehicleProducts()
            ->all());
    }
}
