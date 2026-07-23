<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\SpanishPostalCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguratorPostalCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_canary_postal_code_returns_installations_for_its_island(): void
    {
        SpanishPostalCode::create([
            'postal_code' => '35120',
            'place_name' => 'Arguineguín',
            'province' => 'Las Palmas',
            'autonomous_community' => 'Canarias',
            'island' => 'Gran Canaria',
            'latitude' => 27.7565,
            'longitude' => -15.6808,
            'source' => 'Test',
        ]);

        ConfiguratorProduct::create([
            'handle' => 'installazione-gran-canaria',
            'category' => 'installation',
            'subtype' => 'screen_only',
            'title' => 'Installazione Gran Canaria',
            'price_min' => 100,
            'meta' => [
                'installation' => [
                    'location' => 'GRAN CANARIA',
                    'type' => 'screen_only',
                ],
            ],
        ]);

        $this->getJson('/configurator/postal-code/35120')
            ->assertOk()
            ->assertJsonPath('island', 'Gran Canaria')
            ->assertJsonPath('installationArea.name', 'Gran Canaria')
            ->assertJsonPath('installationArea.productHandles.0', 'installazione-gran-canaria');
    }
}
