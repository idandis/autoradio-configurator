<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\ConfiguratorVariant;
use App\Models\ItalianOrder;
use App\Models\User;
use App\Services\ItalianCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ItalianCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_checkout_links_redirect_to_language_neutral_urls(): void
    {
        $token = '363d7fd2-bfa9-4705-8ccf-ce4f7f551b29';

        foreach ([$token => $token, "preventivo/$token" => "quote/$token", "$token/pagamento" => "$token/payment", "$token/conferma" => "$token/confirmation"] as $old => $new) {
            $this->get("/checkout/italiano/$old")->assertStatus(308)->assertRedirect("/checkout/$new");
        }

        $this->post("/checkout/italiano/$token")->assertStatus(308)->assertRedirect("/checkout/$token");
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['italian_checkout.enabled' => true]);
    }

    private function variant(string $price = '299.90', string $category = 'screen'): ConfiguratorVariant
    {
        $product = ConfiguratorProduct::create([
            'handle' => 'radio-'.uniqid(), 'category' => $category,
            'title' => 'Radio para coche', 'title_it' => 'Autoradio per auto',
        ]);

        return $product->variants()->create(['title' => '4 GB / 64 GB', 'sku' => 'TEST-464', 'price' => $price]);
    }

    private function start(ConfiguratorVariant $variant, int $quantity = 1): array
    {
        $response = $this->post(route('italian-checkout.start'), ['items' => [
            ['type' => 'variant', 'id' => $variant->id, 'quantity' => $quantity],
        ]])->assertSessionHasNoErrors()->assertRedirect();
        $token = basename($response->headers->get('Location'));

        return [$token, session("italian_checkout_drafts.$token.quote_hash")];
    }

    private function customer(string $hash): array
    {
        return [
            'first_name' => 'Maria', 'last_name' => 'Rossi', 'email' => 'maria@example.test',
            'phone' => '+39 333 1234567', 'line1' => 'Via Roma 10', 'line2' => 'Interno 2',
            'postal_code' => '00100', 'city' => 'Roma', 'province' => 'rm', 'country' => 'IT',
            'reviewed' => true, 'quote_hash' => $hash,
        ];
    }

    public function test_guest_can_complete_checkout_with_free_shipping_and_server_prices(): void
    {
        $variant = $this->variant();
        [$token, $hash] = $this->start($variant, 2);
        $this->get(route('italian-checkout.show', $token))->assertInertia(fn (Assert $page) => $page
            ->component('ItalianCheckout/Checkout')
            ->where('quote.subtotal_amount', 59980)->where('quote.shipping_amount', 0)
            ->where('quote.discount_amount', 1799)->where('quote.total_amount', 58181)
            ->where('quote.items.0.title', 'Autoradio per auto'));

        $this->post(route('italian-checkout.store', $token), [
            ...$this->customer($hash), 'total_amount' => 1, 'shipping_amount' => 500,
            'payment_status' => 'paid', 'is_test' => false, 'discount_amount' => 59980,
            'items' => [],
        ])->assertSessionHasNoErrors()->assertRedirect(route('italian-checkout.payment', $token));

        $order = ItalianOrder::sole();
        $this->assertSame(58181, $order->total_amount);
        $this->assertSame(0, $order->shipping_amount);
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->paid_at);
        $this->assertTrue($order->is_test);
        $this->assertSame('00100', $order->shipping_address['postal_code']);
        $this->assertSame('RM', $order->shipping_address['province']);
        $this->assertSame(2, $order->items->sole()->quantity);
        $this->assertSame(29990, $order->items->sole()->unit_amount);
        $this->assertSame('Radio para coche', $variant->product->fresh()->title);

        $this->get(route('italian-checkout.confirmation', $token))->assertInertia(fn (Assert $page) => $page
            ->component('ItalianCheckout/Confirmation')->where('order.number', $order->number)
            ->where('order.is_test', true)->missing('order.internal_notes')->missing('order.email'));
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('italian-orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('order.items.0.title', 'Autoradio per auto')->where('order.is_test', true));
    }

    public function test_repeated_submission_and_refresh_do_not_duplicate_orders(): void
    {
        [$token, $hash] = $this->start($this->variant());
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('italian-checkout.store', $token), $this->customer($hash))
                ->assertRedirect(route('italian-checkout.payment', $token));
        }
        $this->get(route('italian-checkout.show', $token))->assertRedirect(route('italian-checkout.payment', $token));
        $this->assertDatabaseCount('italian_orders', 1);
        $this->assertDatabaseCount('italian_order_items', 1);
    }

    public function test_price_change_requires_review_before_order_creation(): void
    {
        $variant = $this->variant();
        [$token, $hash] = $this->start($variant);
        $variant->update(['price' => '399.90']);
        $this->post(route('italian-checkout.store', $token), $this->customer($hash))->assertSessionHasErrors('quote_hash');
        $this->assertDatabaseCount('italian_orders', 0);
        $this->get(route('italian-checkout.show', $token))->assertInertia(fn (Assert $page) => $page
            ->where('changed', true)->where('quote.total_amount', 39190));
        $updatedHash = session("italian_checkout_drafts.$token.quote_hash");
        $this->post(route('italian-checkout.store', $token), $this->customer($updatedHash))->assertSessionHasNoErrors();
        $this->assertSame(39190, ItalianOrder::sole()->total_amount);
    }

    public function test_deleted_product_does_not_create_partial_order_or_redirect_loop(): void
    {
        $variant = $this->variant();
        [$token, $hash] = $this->start($variant);
        $variant->product->delete();
        $this->post(route('italian-checkout.store', $token), $this->customer($hash))->assertSessionHasErrors('items');
        $this->get(route('italian-checkout.show', $token))->assertInertia(fn (Assert $page) => $page
            ->where('quote', null)->where('unavailable', fn ($value) => is_string($value) && $value !== ''));
        $this->assertDatabaseCount('italian_orders', 0);
        $this->assertDatabaseCount('italian_order_items', 0);
    }

    public function test_installation_and_unknown_articles_are_rejected(): void
    {
        $installation = $this->variant('99.00', 'installation');
        foreach ([['type' => 'variant', 'id' => $installation->id], ['type' => 'product', 'id' => $installation->configurator_product_id], ['type' => 'variant', 'id' => 99999]] as $item) {
            $this->post(route('italian-checkout.start'), ['items' => [[...$item, 'quantity' => 1]]])->assertSessionHasErrors('items');
        }
        $this->assertDatabaseCount('italian_orders', 0);
    }

    public function test_quantities_and_nested_price_injection_are_rejected(): void
    {
        $variant = $this->variant();
        foreach ([0, -1, 100, 1.5] as $quantity) {
            $this->post(route('italian-checkout.start'), ['items' => [
                ['type' => 'variant', 'id' => $variant->id, 'quantity' => $quantity],
            ]])->assertSessionHasErrors('items.0.quantity');
        }
        $this->post(route('italian-checkout.start'), ['items' => [
            ['type' => 'variant', 'id' => $variant->id, 'quantity' => 1, 'unit_amount' => 1],
        ]])->assertSessionHasErrors('items.0');
        $this->post(route('italian-checkout.start'), ['items' => []])->assertSessionHasErrors('items');
        $this->post(route('italian-checkout.start'), ['items' => [
            ['type' => 'variant', 'id' => $variant->id, 'quantity' => 60],
            ['type' => 'variant', 'id' => $variant->id, 'quantity' => 60],
        ]])->assertSessionHasErrors('items');
    }

    public function test_custom_quote_import_costs_and_discount_are_paid_and_persisted(): void
    {
        $variant = $this->variant('100.00');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $response = $this->post(route('italian-checkout.start'), [
            'items' => [[
                'type' => 'variant', 'id' => $variant->id, 'quantity' => 2,
                'import_unit_amount' => 1250,
            ]],
            'custom_discount' => ['code' => 'CLIENTE10', 'type' => 'percentage', 'value' => 1000],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $token = basename($response->headers->get('Location'));

        $this->get(route('italian-checkout.show', $token))->assertInertia(fn (Assert $page) => $page
            ->where('checkoutLocale', 'it')
            ->where('quote.subtotal_amount', 20000)
            ->where('quote.import_amount', 2500)
            ->where('quote.discount_amount', 2000)
            ->where('quote.total_amount', 20500)
            ->where('quote.items.0.import_unit_amount', 1250)
            ->where('quote.items.0.import_total_amount', 2500));

        $this->post(route('italian-checkout.store', $token), $this->customer(
            session("italian_checkout_drafts.$token.quote_hash")
        ))->assertSessionHasNoErrors();

        $order = ItalianOrder::sole();
        $this->assertSame(2500, $order->import_amount);
        $this->assertSame(20500, $order->total_amount);
        $this->assertSame(1250, $order->items->sole()->import_unit_amount);
        $this->assertSame(2500, $order->items->sole()->import_total_amount);
    }

    public function test_flat_import_cost_is_added_once_and_persisted_without_quantity_multiplier(): void
    {
        $variant = $this->variant('100.00');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $payload = [
            'items' => [['type' => 'variant', 'id' => $variant->id, 'quantity' => 3]],
            'import_amount' => 2501,
            'custom_discount' => ['code' => 'TEN', 'type' => 'percentage', 'value' => 1000],
        ];
        $response = $this->post(route('italian-checkout.start'), $payload)->assertSessionHasNoErrors();
        $token = basename($response->headers->get('Location'));
        $this->get(route('italian-checkout.show', $token))->assertInertia(fn (Assert $page) => $page
            ->where('quote.import_amount', 2501)
            ->where('quote.items.0.import_unit_amount', 0)
            ->where('quote.total_amount', 29501));
        $other = $this->post(route('italian-checkout.start'), [...$payload, 'import_amount' => 3000]);
        $this->assertNotSame($response->headers->get('Location'), $other->headers->get('Location'));
        $this->post(route('italian-checkout.store', $token), $this->customer(
            session("italian_checkout_drafts.$token.quote_hash")
        ))->assertSessionHasNoErrors();
        $order = ItalianOrder::sole();
        $this->assertSame(2501, $order->import_amount);
        $this->assertSame(29501, $order->total_amount);
    }

    public function test_flat_import_cost_rejects_public_or_invalid_amounts(): void
    {
        $variant = $this->variant();
        $payload = ['items' => [['type' => 'variant', 'id' => $variant->id, 'quantity' => 1]]];
        $this->post(route('italian-checkout.start'), [...$payload, 'import_amount' => 100])
            ->assertSessionHasErrors('import_amount');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([-1, 1.5, 100000000, 'invalid'] as $amount) {
            $this->post(route('italian-checkout.start'), [...$payload, 'import_amount' => $amount])
                ->assertSessionHasErrors('import_amount');
        }
    }

    public function test_public_requests_cannot_inject_import_costs_or_custom_discounts(): void
    {
        $variant = $this->variant('100.00');
        $item = ['type' => 'variant', 'id' => $variant->id, 'quantity' => 1];

        $this->post(route('italian-checkout.start'), ['items' => [[...$item, 'import_unit_amount' => 1000]]])
            ->assertSessionHasErrors('items');
        $this->post(route('italian-checkout.start'), [
            'items' => [$item],
            'custom_discount' => ['code' => 'FAKE', 'type' => 'fixed', 'value' => 9999],
        ])->assertSessionHasErrors('custom_discount');
        $this->assertDatabaseCount('italian_orders', 0);
    }

    public function test_spanish_checkout_uses_canario_host_language_and_address(): void
    {
        $variant = $this->variant('100.00');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $response = $this->post('https://config.autoradiocanario.com/checkout', [
            'items' => [[
                'type' => 'variant', 'id' => $variant->id, 'quantity' => 1,
                'import_unit_amount' => 1500,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $token = basename($response->headers->get('Location'));

        $this->get('https://config.autoradiocanario.com/checkout/'.$token)
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkoutLocale', 'es')
                ->where('quote.items.0.title', 'Radio para coche')
                ->where('quote.import_amount', 1500)
                ->where('quote.total_amount', 11500));

        $this->post('https://config.autoradiocanario.com/checkout/'.$token, [
            'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.test',
            'phone' => '+34 600 123 123', 'line1' => 'Avenida Mencey 49', 'line2' => null,
            'postal_code' => '35120', 'city' => 'Mogán', 'province' => 'Las Palmas', 'country' => 'ES',
            'reviewed' => true, 'quote_hash' => session("italian_checkout_drafts.$token.quote_hash"),
        ])->assertSessionHasNoErrors();

        $order = ItalianOrder::sole();
        $this->assertSame('es', $order->checkout_locale);
        $this->assertSame('ES', $order->shipping_address['country']);
        $this->assertStringStartsWith('ES-', $order->number);
        $this->assertSame('https://config.autoradiocanario.com', $order->checkout_origin);
    }

    public function test_local_checkout_uses_the_language_selected_in_the_configurator(): void
    {
        $variant = $this->variant('100.00');
        $response = $this->post(route('italian-checkout.start'), [
            'locale' => 'es',
            'items' => [['type' => 'variant', 'id' => $variant->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $token = basename($response->headers->get('Location'));

        $this->get(route('italian-checkout.show', $token))->assertInertia(fn (Assert $page) => $page
            ->where('checkoutLocale', 'es')
            ->where('quote.items.0.title', 'Radio para coche'));
    }

    public function test_missing_or_zero_prices_and_ambiguous_product_selection_are_rejected(): void
    {
        $variant = $this->variant('0.00');
        $this->post(route('italian-checkout.start'), ['items' => [
            ['type' => 'variant', 'id' => $variant->id, 'quantity' => 1],
        ]])->assertSessionHasErrors('items');
        $variant->update(['price' => null]);
        $this->post(route('italian-checkout.start'), ['items' => [
            ['type' => 'variant', 'id' => $variant->id, 'quantity' => 1],
        ]])->assertSessionHasErrors('items');
        $variant->product->update(['price_min' => '10.00']);
        $this->post(route('italian-checkout.start'), ['items' => [
            ['type' => 'product', 'id' => $variant->configurator_product_id, 'quantity' => 1],
        ]])->assertSessionHasErrors('items');
    }

    public function test_products_without_variants_can_be_purchased(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'camera', 'category' => 'camera', 'title' => 'Camera', 'price_min' => '15.95']);
        $response = $this->post(route('italian-checkout.start'), ['items' => [
            ['type' => 'product', 'id' => $product->id, 'quantity' => 1],
        ]])->assertSessionHasNoErrors();
        $token = basename($response->headers->get('Location'));
        $this->post(route('italian-checkout.store', $token), $this->customer(session("italian_checkout_drafts.$token.quote_hash")))
            ->assertSessionHasNoErrors();
        $this->assertSame(1595, ItalianOrder::sole()->total_amount);
    }

    public function test_customer_validation_keeps_invalid_addresses_out_of_orders(): void
    {
        [$token, $hash] = $this->start($this->variant());
        $this->post(route('italian-checkout.store', $token), [
            ...$this->customer($hash), 'email' => 'invalid', 'postal_code' => '123', 'province' => 'Roma', 'country' => 'ES', 'reviewed' => false,
        ])->assertSessionHasErrors(['email', 'postal_code', 'province', 'country', 'reviewed']);
        $this->assertDatabaseCount('italian_orders', 0);
    }

    public function test_other_sessions_cannot_read_checkout_or_confirmation_or_submit(): void
    {
        [$token, $hash] = $this->start($this->variant());
        $this->post(route('italian-checkout.store', $token), $this->customer($hash))->assertSessionHasNoErrors();
        $this->withSession(['italian_checkout_drafts' => []]);
        $this->get(route('italian-checkout.show', $token))->assertNotFound();
        $this->get(route('italian-checkout.confirmation', $token))->assertNotFound();
        $this->post(route('italian-checkout.store', $token), $this->customer($hash))->assertNotFound();
    }

    public function test_same_cart_reuses_token_and_discount_thresholds_match_configurator(): void
    {
        $variant = $this->variant();
        [$token] = $this->start($variant);
        [$sameToken] = $this->start($variant);
        $this->assertSame($token, $sameToken);
        foreach (['299.99' => 0, '300.00' => 600, '499.99' => 1000, '500.00' => 1500, '899.99' => 2700, '900.00' => 5000] as $price => $discount) {
            $variant->update(['price' => $price]);
            $quote = app(ItalianCheckout::class)->quote([['type' => 'variant', 'id' => $variant->id, 'quantity' => 1]]);
            $this->assertSame($discount, $quote['discount_amount']);
            $this->assertSame(0, $quote['shipping_amount']);
        }
    }

    public function test_checkout_is_disabled_in_production_even_when_flag_is_enabled(): void
    {
        $this->app->instance('env', 'production');
        $this->assertFalse(ItalianCheckout::enabled());
        $this->withSession(['_token' => 'test-csrf'])->post(route('italian-checkout.start'), ['items' => [], '_token' => 'test-csrf'])->assertNotFound();
    }
}
