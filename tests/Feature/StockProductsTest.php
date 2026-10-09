<?php

namespace Tests\Feature;

use App\Http\Middleware\BlockOutsideEurope;
use App\Models\ConfiguratorProduct;
use App\Models\StockProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockProductsTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $handle = 'radio-stock'): ConfiguratorProduct
    {
        return ConfiguratorProduct::create([
            'handle' => $handle, 'category' => 'screen', 'title' => 'Radio española',
            'title_it' => 'Autoradio italiana', 'price_min' => 199, 'image_url' => '/images/logo-it.png',
        ]);
    }

    public function test_italian_domain_opens_home_and_preserves_configurator_and_contact_links(): void
    {
        $this->withoutMiddleware(BlockOutsideEurope::class);
        foreach (['autoradioitaliano.it', 'www.autoradioitaliano.it'] as $host) {
            $this->get('https://'.$host.'/')->assertInertia(fn (Assert $page) => $page
                ->component('Configurator')->where('homeBeta', true)->where('locale', 'it'));
            $this->get('https://'.$host.'/configurator')->assertInertia(fn (Assert $page) => $page->missing('homeBeta'));
            $this->get('https://'.$host.'/?form=autoradio')->assertInertia(fn (Assert $page) => $page->missing('homeBeta'));
        }
        $this->get('https://www.autoradiocanario.com/')->assertInertia(fn (Assert $page) => $page->missing('homeBeta'));
    }

    public function test_admin_can_select_update_and_remove_existing_stock_products(): void
    {
        $product = $this->product();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson(route('stock-products.store'), ['product_handle' => $product->handle, 'quantity' => 3])->assertOk();
        $this->postJson(route('stock-products.store'), ['product_handle' => $product->handle, 'quantity' => 2])->assertOk();
        $this->assertSame(1, StockProduct::count());
        $stock = StockProduct::firstOrFail();
        $this->getJson(route('stock-products.index'))->assertJsonPath('stock.0.quantity', 2);
        $this->patchJson(route('stock-products.update', $stock), ['quantity' => 0])->assertOk();
        $this->assertCount(0, StockProduct::publicProducts());
        $this->deleteJson(route('stock-products.destroy', $stock))->assertOk();
        $this->assertModelMissing($stock);
        $this->assertModelExists($product);
    }

    public function test_stock_endpoints_are_admin_only(): void
    {
        $product = $this->product();
        $stock = StockProduct::create(['product_handle' => $product->handle, 'quantity' => 1]);
        $this->getJson(route('stock-products.index'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->getJson(route('stock-products.index'))->assertForbidden();
        $this->postJson(route('stock-products.store'), ['product_handle' => $product->handle, 'quantity' => 2])->assertForbidden();
        $this->patchJson(route('stock-products.update', $stock), ['quantity' => 2])->assertForbidden();
        $this->deleteJson(route('stock-products.destroy', $stock))->assertForbidden();
        $this->assertSame(1, $stock->fresh()->quantity);
    }

    public function test_stock_rejects_unknown_products_installation_and_invalid_quantities(): void
    {
        $product = $this->product();
        ConfiguratorProduct::create(['handle' => 'installation', 'category' => 'installation', 'title' => 'Installazione']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([['missing', 1], ['installation', 1], [$product->handle, -1], [$product->handle, 1.5]] as [$handle, $qty]) {
            $this->postJson(route('stock-products.store'), ['product_handle' => $handle, 'quantity' => $qty])->assertUnprocessable();
        }
        $this->assertSame(0, StockProduct::count());
    }

    public function test_public_beta_shows_only_selected_available_products_in_italian(): void
    {
        $product = $this->product();
        $empty = $this->product('empty-stock');
        $this->product('unselected');
        StockProduct::create(['product_handle' => $product->handle, 'quantity' => 2]);
        StockProduct::create(['product_handle' => $empty->handle, 'quantity' => 0]);
        StockProduct::create(['product_handle' => 'deleted-product', 'quantity' => 4]);
        $this->withoutMiddleware(BlockOutsideEurope::class)->get(route('home.beta'))
            ->assertInertia(fn (Assert $page) => $page->component('Configurator')
                ->where('homeBeta', true)->where('locale', 'it')->has('stockProducts', 1)
                ->where('stockProducts.0.id', $product->id)->where('stockProducts.0.title', 'Autoradio italiana')
                ->where('stockProducts.0.price', 199));
        $this->getJson(route('configurator.catalog.product-details', $product))->assertJsonPath('product.title', 'Autoradio italiana');
        $this->get(route('configurator.show'))->assertInertia(fn (Assert $page) => $page->missing('homeBeta'));
    }

    public function test_stock_selection_survives_replacement_of_the_catalog_product(): void
    {
        $product = $this->product();
        StockProduct::create(['product_handle' => $product->handle, 'quantity' => 2]);
        $id = $product->id;
        $product->delete();
        $replacement = $this->product();
        $this->assertNotSame($id, $replacement->id);
        $this->assertSame($replacement->id, StockProduct::publicProducts()->first()['id']);
        $this->assertSame(2, StockProduct::first()->quantity);
    }

    public function test_admin_can_search_catalog_by_variant_sku(): void
    {
        $product = $this->product();
        $product->variants()->create(['title' => '4GB', 'sku' => 'STOCK-4GB', 'price' => 199]);
        $this->product('other-radio');
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->getJson(route('stock-products.index', ['search' => 'STOCK-4GB']))->assertOk()
            ->assertJsonCount(1, 'products')->assertJsonPath('products.0.handle', $product->handle);
    }
}
