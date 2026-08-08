<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConfiguratorCameraVariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_camera_variant_is_exposed_as_a_selectable_option(): void
    {
        $camera = ConfiguratorProduct::create([
            'handle' => 'camera-specifica-test',
            'category' => 'camera',
            'subtype' => 'rear',
            'title' => 'Camera specifica test',
            'price_min' => 39,
            'image_url' => 'https://example.com/camera.jpg',
            'brand' => 'FIAT',
            'model' => 'Panda',
            'year_from' => 2012,
            'year_to' => 2020,
        ]);

        $firstVariant = $camera->variants()->create([
            'title' => 'CVBS',
            'option_value' => 'CVBS',
            'price' => 39,
            'shopify_variant_id' => '1001',
        ]);
        $secondVariant = $camera->variants()->create([
            'title' => 'AHD',
            'option_value' => 'AHD',
            'price' => 49,
            'shopify_variant_id' => '1002',
        ]);

        $this->get('/configurator')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Configurator')
                ->has('cameraOptions', 1)
                ->where('cameraOptions.0.key', 'camera-specifica-test')
                ->where('cameraOptions.0.variantId', $firstVariant->id)
                ->where('cameraOptions.0.variantTitle', 'CVBS')
                ->has('cameraOptions.0.variants', 2)
                ->where('cameraOptions.0.variants.0.title', 'CVBS')
                ->where('cameraOptions.0.variants.1.id', $secondVariant->id)
                ->where('cameraOptions.0.variants.1.title', 'AHD')
            );
    }
}
