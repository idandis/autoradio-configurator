<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\ItalianOrder;
use App\Models\SharedConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_public_visitor_can_open_shared_configuration(): void
    {
        $configuration = [
            'brand' => 'Toyota',
            'model' => 'RAV4',
            'year' => 2004,
            'screens' => [[
                'product' => 'android-rav4',
                'variant' => '123456789',
            ]],
            'cameras' => ['camera-rav4'],
            'speakers' => [],
            'customProducts' => [],
            'quantities' => ['custom:example' => 3],
            'importCosts' => ['example' => 12.50],
            'installation' => 'installation-screen',
            'postalCode' => '35120',
            'serviceZone' => 'south',
            'precheck' => 'self',
        ];
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->postJson('/configurator/shared-configurations', compact('configuration'))
            ->assertCreated()
            ->assertJsonStructure(['uuid']);

        $uuid = $response->json('uuid');
        $this->assertDatabaseHas('shared_configurations', ['uuid' => $uuid]);

        $this->get('/configurator?c='.$uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Configurator')
                ->where('sharedConfiguration', $configuration)
            );
    }

    public function test_invalid_or_unknown_uuid_does_not_restore_a_configuration(): void
    {
        $this->get('/configurator?c=not-a-uuid')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Configurator')
                ->where('sharedConfiguration', null)
            );
    }

    public function test_admin_can_create_a_permanent_payment_link_with_a_frozen_quote(): void
    {
        config(['italian_checkout.enabled' => true]);
        $product = ConfiguratorProduct::create([
            'handle' => 'pantalla-presupuesto',
            'category' => 'screen',
            'title' => 'Pantalla cliente',
            'title_it' => 'Schermo cliente',
        ]);
        $variant = $product->variants()->create([
            'title' => '8 GB / 128 GB',
            'sku' => 'CUSTOM-8128',
            'price' => '100.00',
        ]);
        $configuration = [
            'mode' => 'universal', 'din' => '2DIN', 'brand' => null, 'model' => null, 'year' => null,
            'screens' => [], 'cameras' => [], 'speakers' => [], 'customProducts' => ['pantalla-presupuesto'],
            'quantities' => ['custom:pantalla-presupuesto' => 2],
            'importTotal' => 40,
            'installation' => null, 'postalCode' => null, 'serviceZone' => null, 'precheck' => null,
        ];
        $checkout = [
            'items' => [[
                'type' => 'variant', 'id' => $variant->id, 'quantity' => 2,
            ]],
            'custom_discount' => null,
            'import_amount' => 4000,
            'locale' => 'es',
        ];
        $admin = User::factory()->create(['is_admin' => true]);

        $first = $this->actingAs($admin)->postJson('/configurator/shared-configurations', compact('configuration', 'checkout'))
            ->assertCreated()->assertJsonStructure(['uuid', 'checkout_url']);
        $second = $this->postJson('/configurator/shared-configurations', compact('configuration', 'checkout'))
            ->assertCreated();
        $uuid = $first->json('uuid');

        $this->assertSame($uuid, $second->json('uuid'));
        $this->assertSame("/checkout/quote/{$uuid}", $first->json('checkout_url'));
        $this->assertDatabaseCount('shared_configurations', 1);
        $this->assertSame(24000, SharedConfiguration::sole()->checkout['quote']['total_amount']);

        $variant->update(['price' => '999.00']);
        auth()->logout();
        $this->flushSession();
        $this->get($first->json('checkout_url'))
            ->assertRedirect(route('italian-checkout.show', $uuid, false));
        $this->get(route('italian-checkout.show', $uuid, false))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ItalianCheckout/Checkout')
                ->where('checkoutLocale', 'es')
                ->where('changed', false)
                ->where('quote.items.0.unit_amount', 10000)
                ->where('quote.items.0.import_unit_amount', 0)
                ->where('quote.import_amount', 4000)
                ->where('quote.total_amount', 24000));

        $hash = session("italian_checkout_drafts.$uuid.quote_hash");
        $this->post(route('italian-checkout.store', $uuid, false), [
            'first_name' => 'Maria', 'last_name' => 'García', 'email' => 'maria@example.test',
            'phone' => '+34 600 123 123', 'line1' => 'Calle Mayor 10', 'line2' => null,
            'postal_code' => '35120', 'city' => 'Mogán', 'province' => 'Las Palmas', 'country' => 'ES',
            'reviewed' => true, 'quote_hash' => $hash,
        ])->assertSessionHasNoErrors()->assertRedirect(route('italian-checkout.payment', $uuid, false));

        $order = ItalianOrder::sole();
        $this->assertSame(24000, $order->total_amount);
        $this->assertSame(4000, $order->import_amount);
        $this->assertSame('es', $order->checkout_locale);
    }

    public function test_quote_number_is_reserved_with_the_payment_link_after_language_change(): void
    {
        config(['italian_checkout.enabled' => true]);
        $product = ConfiguratorProduct::create([
            'handle' => 'language-change-screen', 'category' => 'screen',
            'title' => 'Pantalla', 'title_it' => 'Schermo',
        ]);
        $variant = $product->variants()->create(['title' => 'Base', 'price' => '150.00']);
        $configuration = [
            'mode' => 'universal', 'din' => '2DIN', 'brand' => null, 'model' => null, 'year' => null,
            'screens' => [], 'cameras' => [], 'speakers' => [], 'customProducts' => ['language-change-screen'],
            'quantities' => [], 'importCosts' => [], 'installation' => null,
            'postalCode' => null, 'serviceZone' => null, 'precheck' => null,
        ];
        $admin = User::factory()->create(['is_admin' => true]);

        $numbers = [];
        foreach (['it', 'es'] as $locale) {
            $response = $this->actingAs($admin)->postJson('/configurator/shared-configurations', [
                'configuration' => $configuration,
                'checkout' => [
                    'items' => [['type' => 'variant', 'id' => $variant->id, 'quantity' => 1]],
                    'custom_discount' => null,
                    'locale' => $locale,
                ],
                'reserve_quote_number' => true,
            ])->assertCreated()->assertJsonStructure(['checkout_url', 'quote_number']);
            $numbers[] = $response->json('quote_number');
        }

        $this->assertMatchesRegularExpression('/^ARC-\d{8}-001$/', $numbers[0]);
        $this->assertMatchesRegularExpression('/^ARC-\d{8}-002$/', $numbers[1]);
        $this->assertDatabaseCount('shared_configurations', 2);
    }

    public function test_prune_command_deletes_only_shared_configurations_older_than_30_days(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');

        $expired = SharedConfiguration::create([
            'uuid' => fake()->uuid(),
            'configuration' => ['screens' => []],
        ]);
        $expired->forceFill([
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ])->saveQuietly();

        $current = SharedConfiguration::create([
            'uuid' => fake()->uuid(),
            'configuration' => ['screens' => []],
        ]);
        $permanentPaymentLink = SharedConfiguration::create([
            'uuid' => fake()->uuid(),
            'fingerprint' => hash('sha256', 'permanent-payment-link'),
            'configuration' => ['screens' => []],
            'checkout' => ['items' => [], 'quote' => [], 'locale' => 'es'],
        ]);
        $permanentPaymentLink->forceFill([
            'created_at' => now()->subDays(90),
            'updated_at' => now()->subDays(90),
        ])->saveQuietly();

        $this->artisan('shared-configurations:prune')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($current);
        $this->assertModelExists($permanentPaymentLink);
        Carbon::setTestNow();
    }
}
