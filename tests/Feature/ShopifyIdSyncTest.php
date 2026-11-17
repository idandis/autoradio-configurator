<?php

namespace Tests\Feature;

use App\Jobs\SyncMissingShopifyIds;
use App\Models\ConfiguratorProduct;
use App\Services\ShopifyIdSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyIdSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_only_unique_shopify_variant_matches_without_changing_prices(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'seat-ateca-2016', 'category' => 'screen', 'title' => 'SEAT Ateca']);
        $first = $product->variants()->create(['title' => '4Core 2GB 32GB', 'sku' => 'ARC-1', 'price' => 225]);
        $second = $product->variants()->create(['title' => '8Core 8GB 128GB', 'sku' => 'ARC-2', 'price' => 589]);
        Http::fake(['*/products/seat-ateca-2016.js' => Http::response([
            'id' => 987, 'handle' => 'seat-ateca-2016', 'variants' => [
                ['id' => 1001, 'sku' => 'ARC-1', 'option1' => '4Core 2GB 32GB'],
                ['id' => 1002, 'sku' => 'OTHER', 'option1' => '8Core 8GB 128GB'],
            ],
        ])]);

        $result = app(ShopifyIdSync::class)->sync($product->load('variants'));

        $this->assertSame(['status' => 'updated', 'variants' => 2], $result);
        $this->assertSame('987', $product->fresh()->meta['shopify_product_id']);
        $this->assertSame('1001', $first->fresh()->shopify_variant_id);
        $this->assertSame('1002', $second->fresh()->shopify_variant_id);
        $this->assertSame('225.00', $first->fresh()->price);
    }

    public function test_it_does_not_assign_ambiguous_variant_ids(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'seat-ateca-2016', 'category' => 'screen', 'title' => 'SEAT Ateca']);
        $variant = $product->variants()->create(['title' => '8Core 8GB 128GB', 'sku' => 'ARC-1', 'price' => 589]);
        Http::fake(['*/products/seat-ateca-2016.js' => Http::response([
            'id' => 987, 'handle' => 'seat-ateca-2016', 'variants' => [
                ['id' => 1001, 'sku' => 'ARC-1', 'option1' => '8Core 8GB 128GB'],
                ['id' => 1002, 'sku' => 'ARC-1', 'option1' => '8Core 8GB 128GB'],
            ],
        ])]);

        $this->assertSame(['status' => 'unmatched', 'variants' => 0], app(ShopifyIdSync::class)->sync($product->load('variants')));
        $this->assertNull($variant->fresh()->shopify_variant_id);
    }

    public function test_job_finds_products_without_variant_ids(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'seat-ateca-2016', 'category' => 'screen', 'title' => 'SEAT Ateca']);
        $variant = $product->variants()->create(['title' => 'Default Title', 'sku' => 'ARC-1', 'price' => 225]);
        Http::fake(['*/products/seat-ateca-2016.js' => Http::response([
            'id' => 987, 'handle' => 'seat-ateca-2016', 'variants' => [['id' => 1001, 'sku' => 'ARC-1', 'option1' => 'Default Title']],
        ])]);

        (new SyncMissingShopifyIds(1))->handle(app(ShopifyIdSync::class));

        $this->assertSame('1001', $variant->fresh()->shopify_variant_id);
        $this->assertSame('finished', \Illuminate\Support\Facades\Cache::get('shopify-id-sync:1')['status']);
    }
}
