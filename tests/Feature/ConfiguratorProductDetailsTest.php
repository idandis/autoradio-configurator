<?php

namespace Tests\Feature;

use App\Http\Middleware\BlockOutsideEurope;
use App\Models\ConfiguratorProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguratorProductDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_product_details_include_gallery_variants_and_sanitized_body_html(): void
    {
        $product = ConfiguratorProduct::create([
            'handle' => 'internal-details-product',
            'category' => 'camera',
            'title' => 'Título completo recuperado de la tabla',
            'body_html' => '<p onclick="alert(1)">Descripción</p><script>alert(2)</script><img src="javascript:alert(3)" onerror="alert(4)">',
            'price_min' => 49.90,
            'image_url' => 'https://example.com/main.jpg',
            'meta' => [
                'gallery_images' => ['https://example.com/main.jpg', 'https://example.com/second.jpg'],
                'screen_size' => '9 pulgadas',
            ],
        ]);
        $product->variants()->create([
            'title' => 'Premium',
            'option_value' => 'Premium',
            'price' => 69.90,
            'image_url' => 'https://example.com/variant.jpg',
            'shopify_variant_id' => '123456789',
        ]);

        $response = $this->withoutMiddleware(BlockOutsideEurope::class)
            ->getJson(route('configurator.catalog.product-details', $product));

        $response->assertOk()
            ->assertJsonPath('product.title', 'Título completo recuperado de la tabla')
            ->assertJsonPath('product.images.0', 'https://example.com/main.jpg')
            ->assertJsonPath('product.images.1', 'https://example.com/second.jpg')
            ->assertJsonPath('product.images.2', 'https://example.com/variant.jpg')
            ->assertJsonPath('product.variants.0.title', 'Premium')
            ->assertJsonPath('product.features.0.value', '9 pulgadas');

        $html = $response->json('product.bodyHtml');
        $this->assertStringContainsString('<p>Descripción</p>', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }
}
