<?php

namespace Tests\Feature;

use App\Models\ItalianOrder;
use App\Services\StripeGateway;
use App\Services\StripePayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Stripe\Exception\ApiConnectionException;
use Tests\TestCase;

class StripeTestPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['italian_checkout.enabled' => true, 'stripe.key' => 'pk_test_fake', 'stripe.secret' => 'sk_test_fake', 'stripe.webhook_secret' => 'whsec_fake']);
        $this->gateway = $this->mock(StripeGateway::class);
    }

    private function order(): ItalianOrder
    {
        return ItalianOrder::create([
            'checkout_token' => (string) Str::uuid(), 'is_test' => true,
            'customer_name' => 'Test Cliente', 'email' => 'test@example.test',
            'shipping_address' => ['country' => 'IT'],
            'subtotal_amount' => 10000, 'discount_amount' => 500, 'shipping_amount' => 0,
            'total_amount' => 9500, 'payment_status' => 'pending', 'fulfillment_status' => 'pending',
        ])->refresh();
    }

    private function sessionData(array $payload, string $id = 'cs_test_one'): array
    {
        return [
            'id' => $id, 'object' => 'checkout.session', 'livemode' => false, 'mode' => 'payment',
            'status' => 'open', 'payment_status' => 'unpaid', 'currency' => 'eur',
            'amount_total' => $payload['line_items'][0]['price_data']['unit_amount'],
            'metadata' => $payload['metadata'], 'client_reference_id' => $payload['client_reference_id'],
            'client_secret' => $id.'_secret_private', 'payment_intent' => null,
        ];
    }

    private function reserve(ItalianOrder $order): array
    {
        $this->gateway->shouldReceive('createSession')->once()->andReturnUsing(fn ($payload) => $this->sessionData($payload));
        $session = app(StripePayments::class)->session($order, 'http://localhost/return');

        return [$order->payments()->sole(), $session];
    }

    private function owned(ItalianOrder $order): void
    {
        $this->withSession(['italian_checkout_drafts' => [$order->checkout_token => ['items' => [], 'quote_hash' => 'unused']]]);
    }

    private function webhook(array $session, string $type = 'checkout.session.completed', string $signatureSecret = 'whsec_fake', bool $live = false)
    {
        $body = json_encode(['id' => 'evt_test_one', 'object' => 'event', 'livemode' => $live, 'type' => $type, 'data' => ['object' => $session]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $signatureSecret);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t=$timestamp,v1=$signature",
        ], $body);
    }

    public function test_session_charges_server_total_and_reuses_open_session(): void
    {
        $order = $this->order();
        $this->gateway->shouldReceive('createSession')->once()->andReturnUsing(function ($payload, $key) use ($order) {
            $this->assertSame(9500, $payload['line_items'][0]['price_data']['unit_amount']);
            $this->assertSame('eur', $payload['line_items'][0]['price_data']['currency']);
            $this->assertSame('embedded_page', $payload['ui_mode']);
            $this->assertSame(['card'], $payload['payment_method_types']);
            $this->assertSame((string) $order->id, $payload['metadata']['italian_order_id']);
            $this->assertTrue(Str::isUuid($key));

            return $this->sessionData($payload);
        });
        $service = app(StripePayments::class);
        $session = $service->session($order, 'http://localhost/return');
        $this->gateway->shouldReceive('retrieveSession')->once()->with('cs_test_one')->andReturn($session);
        $this->assertSame($session, $service->session($order, 'http://localhost/different'));
        $this->assertDatabaseCount('italian_order_payments', 1);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_network_retry_reuses_identical_payload_and_idempotency_key(): void
    {
        $order = $this->order();
        $first = null;
        $this->gateway->shouldReceive('createSession')->twice()->andReturnUsing(function ($payload, $key) use (&$first) {
            if ($first === null) {
                $first = [$payload, $key];
                throw ApiConnectionException::factory('Timeout');
            }
            $this->assertSame($first, [$payload, $key]);

            return $this->sessionData($payload);
        });
        try {
            app(StripePayments::class)->session($order, 'http://localhost/return');
            $this->fail('Expected timeout');
        } catch (ApiConnectionException $exception) {
            $this->assertDatabaseCount('italian_order_payments', 1);
        }
        app(StripePayments::class)->session($order, 'http://localhost/changed');
        $this->assertDatabaseCount('italian_order_payments', 1);
    }

    public function test_signed_success_webhook_updates_order_once_without_browser_return(): void
    {
        $order = $this->order();
        [$payment, $session] = $this->reserve($order);
        $paid = [...$session, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_one'];
        $this->gateway->shouldReceive('retrieveSession')->twice()->with($session['id'])->andReturn($paid);
        $this->webhook($paid)->assertOk();
        $date = $order->fresh()->paid_at;
        $this->webhook($paid)->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertNotNull($date);
        $this->assertTrue($date->equalTo($order->paid_at));
        $this->assertSame(2, $order->version);
        $this->assertSame('pi_test_one', $payment->fresh()->stripe_payment_intent_id);
        $this->assertSame(1, $order->events()->where('kind', 'payment')->count());
    }

    public function test_bad_signature_live_events_and_unrelated_events_do_not_update_orders(): void
    {
        $order = $this->order();
        [, $session] = $this->reserve($order);
        $this->webhook($session, signatureSecret: 'whsec_wrong')->assertStatus(400);
        $this->webhook($session, live: true)->assertStatus(400);
        $this->webhook($session, type: 'customer.created')->assertOk();
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_mismatched_amount_currency_mode_and_metadata_are_rejected(): void
    {
        $order = $this->order();
        [, $session] = $this->reserve($order);
        foreach ([['amount_total' => 1], ['currency' => 'usd'], ['livemode' => true], ['client_reference_id' => '999'], ['metadata' => ['italian_order_id' => '999', 'italian_payment_id' => '1']]] as $override) {
            $invalid = [...$session, 'status' => 'complete', 'payment_status' => 'paid', ...$override];
            $this->gateway->shouldReceive('retrieveSession')->once()->andReturn($invalid);
            $this->webhook($session)->assertStatus(400);
        }
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertDatabaseCount('italian_order_events', 0);
    }

    public function test_failed_card_can_be_retried_and_late_failure_cannot_undo_success(): void
    {
        $order = $this->order();
        [$payment, $session] = $this->reserve($order);
        $failed = [...$session, 'payment_intent' => ['id' => 'pi_test', 'status' => 'requires_payment_method', 'last_payment_error' => ['code' => 'card_declined']]];
        $paid = [...$session, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => ['id' => 'pi_test', 'status' => 'succeeded']];
        $this->gateway->shouldReceive('retrieveSession')->times(3)->andReturn($failed, $paid, $paid);
        $intent = ['id' => 'pi_test', 'object' => 'payment_intent', 'metadata' => ['italian_payment_id' => (string) $payment->id]];
        $this->webhook($intent, type: 'payment_intent.payment_failed')->assertOk();
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->webhook($paid)->assertOk();
        $this->webhook($intent, type: 'payment_intent.payment_failed')->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(2, $order->events()->count());
    }

    public function test_expired_session_creates_one_new_attempt_without_new_order(): void
    {
        $order = $this->order();
        [, $session] = $this->reserve($order);
        $expired = [...$session, 'status' => 'expired'];
        $this->gateway->shouldReceive('retrieveSession')->once()->andReturn($expired);
        $this->gateway->shouldReceive('createSession')->once()->andReturnUsing(fn ($payload) => $this->sessionData($payload, 'cs_test_two'));
        app(StripePayments::class)->session($order, 'http://localhost/return');
        $this->assertDatabaseCount('italian_orders', 1);
        $this->assertDatabaseCount('italian_order_payments', 2);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertCount(2, $order->payments()->pluck('idempotency_key')->unique());
    }

    public function test_return_page_checks_saved_session_and_does_not_trust_query_string(): void
    {
        $order = $this->order();
        [, $session] = $this->reserve($order);
        $this->owned($order);
        $this->gateway->shouldReceive('retrieveSession')->once()->with('cs_test_one')->andReturn($session);
        $this->get(route('italian-checkout.confirmation', $order->checkout_token).'?session_id=cs_test_someone_else&payment_status=paid')
            ->assertInertia(fn (Assert $page) => $page->where('order.payment_status', 'pending'));
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_session_endpoint_is_owned_and_does_not_expose_secret_key(): void
    {
        $order = $this->order();
        $this->get(route('italian-checkout.payment', $order->checkout_token))->assertNotFound();
        $this->post(route('italian-checkout.stripe-session', $order->checkout_token))->assertNotFound();
        $this->owned($order);
        $this->get(route('italian-checkout.payment', $order->checkout_token))->assertInertia(fn (Assert $page) => $page
            ->component('ItalianCheckout/Payment')->where('publishableKey', 'pk_test_fake')->missing('secret'));
        $this->gateway->shouldReceive('createSession')->once()->andReturnUsing(fn ($payload) => $this->sessionData($payload));
        $this->post(route('italian-checkout.stripe-session', $order->checkout_token), ['total_amount' => 1])
            ->assertOk()->assertJsonPath('clientSecret', 'cs_test_one_secret_private')->assertDontSee('sk_test_fake');
    }

    public function test_live_keys_and_non_test_orders_are_blocked(): void
    {
        config(['stripe.secret' => 'sk_live_not_allowed']);
        $this->assertFalse(StripePayments::configured());
        config(['stripe.secret' => 'sk_test_fake']);
        $order = $this->order();
        $order->update(['is_test' => false]);
        $this->owned($order);
        $this->post(route('italian-checkout.stripe-session', $order->checkout_token))->assertStatus(503);
        $this->assertDatabaseCount('italian_order_payments', 0);
    }

    public function test_return_page_can_confirm_payment_before_webhook_arrives(): void
    {
        $order = $this->order();
        [, $session] = $this->reserve($order);
        $this->owned($order);
        $paid = [...$session, 'status' => 'complete', 'payment_status' => 'paid'];
        $this->gateway->shouldReceive('retrieveSession')->twice()->with('cs_test_one')->andReturn($paid);
        $this->get(route('italian-checkout.confirmation', $order->checkout_token))
            ->assertInertia(fn (Assert $page) => $page->where('order.payment_status', 'paid')->where('syncError', false));
        $this->webhook($paid)->assertOk();
        $this->assertSame(1, $order->events()->count());
    }

    public function test_webhook_network_failure_is_retryable_and_leaves_order_pending(): void
    {
        $order = $this->order();
        [, $session] = $this->reserve($order);
        $this->gateway->shouldReceive('retrieveSession')->once()->andThrow(ApiConnectionException::factory('Timeout'));
        $this->webhook($session)->assertStatus(503);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }
}
