<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Services\VehicleImageGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleImageGeneratorTest extends TestCase
{
    use RefreshDatabase;

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
