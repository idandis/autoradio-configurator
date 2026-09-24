<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\ItalianOrder;
use App\Models\User;
use App\Mail\ItalianPurchaseConfirmation;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ItalianOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['stripe.mode' => 'test', 'stripe.key' => 'pk_test_fake', 'stripe.secret' => 'sk_test_fake']);
    }

    private function order(array $attributes = []): ItalianOrder
    {
        $order = ItalianOrder::create([
            'customer_name' => 'Mario Rossi',
            'email' => 'mario@example.test',
            'shipping_address' => ['line1' => 'Via Roma 1', 'postal_code' => '00100', 'city' => 'Roma', 'province' => 'RM', 'country' => 'IT'],
            'subtotal_amount' => 29990,
            'shipping_amount' => 1000,
            'total_amount' => 30990,
            'payment_status' => 'pending',
            'fulfillment_status' => 'pending',
            ...$attributes,
        ]);
        $order->items()->create([
            'product_handle' => 'radio-test',
            'sku' => 'RADIO-4-64',
            'title' => 'Autoradio per Mercedes-Benz SL',
            'variant_title' => '4 GB / 64 GB',
            'quantity' => 1,
            'unit_amount' => 29990,
            'total_amount' => 29990,
        ]);

        return $order->refresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_guest_and_non_admin_cannot_read_or_update_orders(): void
    {
        $order = $this->order();
        $this->get(route('italian-orders.index'))->assertRedirect(route('login'));
        $this->get(route('italian-orders.show', $order))->assertRedirect(route('login'));
        $this->patch(route('italian-orders.update', $order), [])->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get(route('italian-orders.index'))->assertForbidden();
        $this->get(route('italian-orders.show', $order))->assertForbidden();
        $this->patch(route('italian-orders.update', $order), [])->assertForbidden();
    }

    public function test_admin_can_view_empty_orders_and_filter_orders(): void
    {
        $this->actingAs($this->admin())->get(route('italian-orders.index'))
            ->assertInertia(fn (Assert $page) => $page->component('ItalianOrders/Index')->has('orders.data', 0));

        $matching = $this->order(['payment_status' => 'paid']);
        $this->order();
        $this->order(['customer_name' => 'Anna Verdi', 'email' => 'anna@example.test', 'payment_status' => 'paid']);

        $this->get(route('italian-orders.index', ['search' => 'Mario', 'payment_status' => 'paid', 'fulfillment_status' => 'pending']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)
                ->where('orders.data.0.id', $matching->id)
                ->where('orders.data.0.total_amount', 30990));
    }

    public function test_admin_can_send_a_preview_email_to_their_own_address(): void
    {
        config([
            'italian_checkout.mail_enabled' => true,
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.from.address' => 'shop@example.test',
        ]);
        Mail::fake();
        $order = $this->order(['payment_status' => 'paid', 'paid_at' => now()]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('italian-orders.purchase-email.test', $order))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('italian-orders.show', $order));

        Mail::assertSent(ItalianPurchaseConfirmation::class, function ($mail) use ($admin, $order) {
            return $mail->preview
                && $mail->order->is($order)
                && $mail->hasTo($admin->email)
                && str_starts_with($mail->envelope()->subject, '[PROVA]');
        });
    }

    public function test_pagination_keeps_filters_and_invalid_filter_is_rejected(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->order();
        }
        $this->actingAs($this->admin())->get(route('italian-orders.index', ['payment_status' => 'pending', 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)
                ->where('orders.total', 26)->where('orders.current_page', 2)
                ->where('orders.prev_page_url', fn ($url) => str_contains($url, 'payment_status=pending')));
        $this->get(route('italian-orders.index', ['payment_status' => 'invalid']))->assertSessionHasErrors('payment_status');
    }

    public function test_order_details_survive_catalog_changes_and_deletion(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'radio-test', 'title' => 'Radio ES', 'category' => 'screen']);
        $variant = $product->variants()->create(['title' => '4 GB / 64 GB', 'price' => '299.90']);
        $order = $this->order();
        $product->update(['title' => 'Titolo nuovo']);
        $variant->update(['price' => '499.90']);
        $product->delete();

        $this->actingAs($this->admin())->get(route('italian-orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page->component('ItalianOrders/Show')
                ->where('order.items.0.title', 'Autoradio per Mercedes-Benz SL')
                ->where('order.items.0.unit_amount', 29990)
                ->where('order.total_amount', 30990)
                ->where('order.shipping_address.city', 'Roma'));
    }

    public function test_unpaid_order_allows_notes_but_cannot_be_prepared_or_shipped(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin());
        foreach (['processing', 'shipped'] as $status) {
            $this->patch(route('italian-orders.update', $order), [
                'version' => 1, 'fulfillment_status' => $status, 'carrier' => 'Corriere', 'tracking_number' => '123',
            ])->assertSessionHasErrors('fulfillment_status');
        }
        $this->patch(route('italian-orders.update', $order), [
            'version' => 1, 'fulfillment_status' => 'pending', 'internal_notes' => 'Cliente contattato.',
        ])->assertSessionHasNoErrors()->assertRedirect(route('italian-orders.show', $order));
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('Cliente contattato.', $order->fresh()->internal_notes);
        $this->assertSame(1, $order->events()->count());
    }

    public function test_shipping_requires_tracking_and_records_actor_and_timestamps(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'paid_at' => now()]);
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->patch(route('italian-orders.update', $order), [
            'version' => 1, 'fulfillment_status' => 'shipped',
        ])->assertSessionHasErrors('tracking_number');

        $data = ['version' => 1, 'fulfillment_status' => 'shipped', 'carrier' => 'Corriere', 'tracking_number' => 'TRACK123'];
        $this->patch(route('italian-orders.update', $order), $data)->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('shipped', $order->fulfillment_status);
        $this->assertNotNull($order->shipped_at);
        $this->assertSame(2, $order->version);
        $this->assertDatabaseHas('italian_order_events', ['italian_order_id' => $order->id, 'user_id' => $admin->id, 'from_status' => 'pending', 'to_status' => 'shipped']);

        $this->patch(route('italian-orders.update', $order), [...$data, 'version' => 2, 'fulfillment_status' => 'pending'])
            ->assertSessionHasErrors('fulfillment_status');
        $this->patch(route('italian-orders.update', $order), [...$data, 'version' => 2, 'fulfillment_status' => 'delivered'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->delivered_at);
        $this->assertTrue($order->shipped_at->equalTo($order->fresh()->shipped_at));
    }

    public function test_stale_update_cannot_overwrite_another_operator(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin())->patch(route('italian-orders.update', $order), [
            'version' => 1, 'fulfillment_status' => 'pending', 'internal_notes' => 'Prima modifica',
        ])->assertSessionHasNoErrors();
        $this->patch(route('italian-orders.update', $order), [
            'version' => 1, 'fulfillment_status' => 'pending', 'internal_notes' => 'Modifica obsoleta',
        ])->assertSessionHasErrors('version');
        $this->assertSame('Prima modifica', $order->fresh()->internal_notes);
        $this->assertSame(1, $order->events()->count());
    }

    public function test_admin_endpoint_cannot_change_payment_totals_or_purchase_snapshots(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin())->patch(route('italian-orders.update', $order), [
            'version' => 1, 'fulfillment_status' => 'pending', 'payment_status' => 'paid', 'total_amount' => 1,
        ])->assertSessionHasErrors(['payment_status', 'total_amount']);
        $this->patch(route('italian-orders.update', $order), [
            'version' => 1, 'fulfillment_status' => 'pending', 'subtotal_amount' => 1,
            'customer_name' => 'Alterato', 'items' => [], 'paid_at' => now()->toIso8601String(),
        ])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->paid_at);
        $this->assertSame('Mario Rossi', $order->customer_name);
        $this->assertSame(29990, $order->subtotal_amount);
        $this->assertSame(30990, $order->total_amount);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(0, $order->events()->count());
    }

    public function test_only_admin_can_remove_test_orders_and_real_orders_are_preserved(): void
    {
        $test = $this->order(['is_test' => true]);
        $real = $this->order();
        $this->delete(route('italian-orders.destroy', $test))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->delete(route('italian-orders.destroy-tests'))->assertForbidden();
        $this->actingAs($this->admin())->delete(route('italian-orders.destroy', $real))->assertForbidden();
        $this->delete(route('italian-orders.destroy', $test))->assertRedirect(route('italian-orders.index'));
        $this->assertSoftDeleted($test);
        $this->get(route('italian-orders.show', $test))->assertNotFound();
        $second = $this->order(['is_test' => true, 'payment_status' => 'paid']);
        $this->delete(route('italian-orders.destroy-tests'))->assertRedirect(route('italian-orders.index'));
        $this->assertSoftDeleted($second);
        $this->assertNotSoftDeleted($real);
        $this->assertSame(1, ItalianOrder::count());
    }

    public function test_deleted_checkout_cannot_be_reopened(): void
    {
        $token = (string) Str::uuid();
        $order = $this->order(['is_test' => true, 'checkout_token' => $token]);
        $order->delete();
        config(['italian_checkout.enabled' => true]);
        $this->withSession(['italian_checkout_drafts' => [$token => ['items' => []]]])
            ->get('/checkout/'.$token)->assertStatus(410);
    }

    public function test_cancellation_keeps_paid_order_visible_and_blocks_further_changes(): void
    {
        $order = $this->order(['payment_status' => 'paid', 'fulfillment_status' => 'processing']);
        $this->actingAs($this->admin())->post(route('italian-orders.cancel', $order), ['version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->fulfillment_status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNotSoftDeleted($order);
        $this->assertSame(1, $order->events()->where('to_status', 'cancelled')->count());
        $this->post(route('italian-orders.cancel', $order), ['version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, $order->events()->count());
        $this->patch(route('italian-orders.update', $order), ['version' => 2, 'fulfillment_status' => 'shipped', 'carrier' => 'BRT', 'tracking_number' => 'TEST'])->assertSessionHasErrors('fulfillment_status');
        $this->get(route('italian-orders.index', ['fulfillment_status' => 'cancelled']))->assertOk();
    }

    public function test_cancellation_requires_admin_current_version_and_unshipped_order(): void
    {
        $order = $this->order();
        $url = route('italian-orders.cancel', $order);
        $this->post($url, ['version' => 1])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post($url, ['version' => 1])->assertForbidden();
        $this->actingAs($this->admin())->post($url, ['version' => 2])->assertSessionHasErrors('version');
        foreach (['shipped', 'delivered'] as $status) {
            $order->update(['fulfillment_status' => $status]);
            $this->post($url, ['version' => 1])->assertSessionHasErrors('cancel');
            $this->assertSame($status, $order->fresh()->fulfillment_status);
        }
    }

    public function test_cancellation_expires_open_stripe_checkout_and_blocks_public_payment(): void
    {
        config(['italian_checkout.enabled' => true]);
        $token = (string) Str::uuid();
        $order = $this->order(['is_test' => true, 'checkout_token' => $token]);
        $order->payments()->create(['idempotency_key' => (string) Str::uuid(), 'status' => 'open', 'stripe_session_id' => 'cs_test_cancel', 'request_payload' => [], 'expires_at' => now()->addHour()->timestamp]);
        $this->mock(StripeGateway::class, function ($mock) {
            $mock->shouldReceive('retrieveSession')->once()->with('cs_test_cancel')->andReturn(['status' => 'open']);
            $mock->shouldReceive('expireSession')->once()->with('cs_test_cancel')->andReturn(['status' => 'expired']);
        });
        $this->actingAs($this->admin())->post(route('italian-orders.cancel', $order), ['version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('expired', $order->payments()->first()->status);
        $this->withSession(['italian_checkout_drafts' => [$token => ['items' => []]]])
            ->get('/checkout/'.$token.'/payment')->assertRedirect('/checkout/'.$token.'/confirmation');
        $this->postJson('/checkout/'.$token.'/stripe-session')->assertJsonStructure(['redirect']);
    }

    public function test_stripe_failure_leaves_order_active(): void
    {
        $order = $this->order(['is_test' => true]);
        $order->payments()->create(['idempotency_key' => (string) Str::uuid(), 'status' => 'open', 'stripe_session_id' => 'cs_test_cancel', 'request_payload' => [], 'expires_at' => now()->addHour()->timestamp]);
        $this->mock(StripeGateway::class, function ($mock) {
            $mock->shouldReceive('retrieveSession')->once()->andThrow(new \LogicException('Not configured'));
        });
        $this->actingAs($this->admin())->post(route('italian-orders.cancel', $order), ['version' => 1])->assertSessionHasErrors('cancel');
        $this->assertSame('pending', $order->fresh()->fulfillment_status);
        $this->assertSame(0, $order->events()->count());
    }
}
