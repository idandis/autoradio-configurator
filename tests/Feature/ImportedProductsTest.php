<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\InstallationZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportedProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_an_imported_product_and_its_related_records(): void
    {
        $product = ConfiguratorProduct::create([
            'handle' => 'installation-test',
            'category' => 'installation',
            'title' => 'Installazione test',
        ]);
        $variant = $product->variants()->create([
            'title' => 'Default',
            'price' => 99,
        ]);
        $zone = InstallationZone::create([
            'name' => 'Zona test',
            'active' => true,
        ]);
        $zone->products()->create([
            'product_handle' => $product->handle,
        ]);

        $response = $this
            ->actingAs(User::factory()->create(['is_admin' => true]))
            ->delete(route('imported-products.destroy', $product));

        $response
            ->assertRedirect()
            ->assertSessionHas('status', 'Prodotto eliminato.');

        $this->assertModelMissing($product);
        $this->assertDatabaseMissing('configurator_variants', ['id' => $variant->id]);
        $this->assertDatabaseMissing('installation_zone_products', [
            'product_handle' => 'installation-test',
        ]);
    }

    public function test_non_admin_cannot_delete_an_imported_product(): void
    {
        $product = ConfiguratorProduct::create([
            'handle' => 'protected-product',
            'category' => 'screen',
            'title' => 'Prodotto protetto',
        ]);

        $this
            ->actingAs(User::factory()->create(['is_admin' => false]))
            ->delete(route('imported-products.destroy', $product))
            ->assertForbidden();

        $this->assertModelExists($product);
    }
}
