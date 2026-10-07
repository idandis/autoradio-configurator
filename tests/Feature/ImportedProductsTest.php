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

    public function test_admin_can_set_variant_prices_and_refresh_the_minimum(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'prices', 'category' => 'screen', 'title' => 'Radio', 'price_min' => 100]);
        $a = $product->variants()->create(['title' => '2GB', 'price' => 100]);
        $b = $product->variants()->create(['title' => '4GB', 'price' => 200]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->patch(route('imported-products.variants', $product), ['variants' => [
                ['id' => $a->id, 'price' => 250.55],
                ['id' => $b->id, 'price' => 150],
            ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('250.55', $a->fresh()->price);
        $this->assertSame('150.00', $b->fresh()->price);
        $this->assertSame('150.00', $product->fresh()->price_min);
    }

    public function test_variant_prices_reject_foreign_variants_and_invalid_values_atomically(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'prices', 'category' => 'screen', 'title' => 'Radio', 'price_min' => 100]);
        $a = $product->variants()->create(['title' => '2GB', 'price' => 100]);
        $other = ConfiguratorProduct::create(['handle' => 'other', 'category' => 'screen', 'title' => 'Other']);
        $b = $other->variants()->create(['title' => '4GB', 'price' => 200]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([['id' => $b->id, 'price' => 150], ['id' => $a->id, 'price' => -1]] as $bad) {
            $this->patch(route('imported-products.variants', $product), ['variants' => [
                ['id' => $a->id, 'price' => 250], $bad,
            ]])->assertSessionHasErrors();
            $this->assertSame('100.00', $a->fresh()->price);
            $this->assertSame('200.00', $b->fresh()->price);
            $this->assertSame('100.00', $product->fresh()->price_min);
        }
    }

    public function test_non_admin_cannot_change_variant_prices(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'prices', 'category' => 'screen', 'title' => 'Radio']);
        $variant = $product->variants()->create(['title' => '2GB', 'price' => 100]);
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->patch(route('imported-products.variants', $product), ['variants' => [['id' => $variant->id, 'price' => 1]]])
            ->assertForbidden();
        $this->assertSame('100.00', $variant->fresh()->price);
    }

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
