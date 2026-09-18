<?php

namespace Tests\Feature;

use App\Models\ItalianOrder;
use App\Models\User;
use App\Services\ItalianOrderRefunds;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Stripe\Exception\ApiConnectionException;
use Tests\TestCase;

class ItalianOrderRefundsTest extends TestCase
{
    use RefreshDatabase;

    private function order(): ItalianOrder
    {
        config(['italian_checkout.enabled' => true, 'stripe.key' => 'pk_test_fake', 'stripe.secret' => 'sk_test_fake']);
        $order = ItalianOrder::create(['customer_name' => 'Test', 'email' => 'test@example.test', 'shipping_address' => [], 'subtotal_amount' => 10000, 'total_amount' => 10000, 'is_test' => true, 'payment_status' => 'paid', 'fulfillment_status' => 'cancelled']);
        $order->payments()->create(['idempotency_key' => (string) Str::uuid(), 'stripe_session_id' => 'cs_test_refund', 'stripe_payment_intent_id' => 'pi_test_refund', 'status' => 'complete', 'request_payload' => [], 'expires_at' => now()->timestamp]);

        return $order->refresh();
    }

    private function gateway(ItalianOrder $order): MockInterface
    {
        $mock = Mockery::mock(StripeGateway::class);
        $mock->shouldReceive('retrievePaymentIntent')->andReturn(['livemode' => false, 'currency' => 'eur', 'amount_received' => 10000, 'status' => 'succeeded', 'metadata' => ['italian_order_id' => (string) $order->id]]);
        $this->app->instance(StripeGateway::class, $mock);

        return $mock;
    }

    private function refundResult(int $amount, string $id = 're_test_one', string $status = 'succeeded'): array
    {
        return ['id' => $id, 'amount' => $amount, 'status' => $status, 'currency' => 'eur', 'payment_intent' => 'pi_test_refund', 'metadata' => ['italian_refund_id' => '1']];
    }

    public function test_partial_then_total_refund_keeps_cancellation_and_prevents_duplicate_submission(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['is_admin' => true]);
        $gateway = $this->gateway($order);
        $gateway->shouldReceive('listRefunds')->andReturn([]);
        $gateway->shouldReceive('createRefund')->once()->with(Mockery::on(fn ($p) => $p['amount'] === 2000), Mockery::type('string'))->andReturn($this->refundResult(2000));
        $this->actingAs($admin)->post(route('italian-orders.refund', $order), ['amount' => '20,00', 'version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
        $this->assertSame('cancelled', $order->fresh()->fulfillment_status);
        $this->post(route('italian-orders.refund', $order), ['amount' => '20,00', 'version' => 1])->assertSessionHasErrors('refund');
        $this->post(route('italian-orders.refund', $order), ['amount' => '80.01', 'version' => 2])->assertSessionHasErrors('amount');
        $result = $this->refundResult(8000, 're_test_two');
        $result['metadata']['italian_refund_id'] = '2';
        $gateway->shouldReceive('createRefund')->once()->with(Mockery::on(fn ($p) => $p['amount'] === 8000), Mockery::type('string'))->andReturn($result);
        $this->post(route('italian-orders.refund', $order), ['amount' => '80', 'version' => 2])->assertSessionHasNoErrors();
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(10000, (int) $order->refunds()->where('status', 'succeeded')->sum('amount'));
        $this->assertSame(2, $order->events()->count());
    }

    public function test_uncertain_network_response_reuses_identical_request_key(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['is_admin' => true]);
        $gateway = $this->gateway($order);
        $gateway->shouldReceive('listRefunds')->andReturn([]);
        $key = null;
        $payload = null;
        $gateway->shouldReceive('createRefund')->once()->withArgs(function ($p, $k) use (&$key, &$payload) {
            $key = $k;
            $payload = $p;

            return true;
        })->andThrow(new ApiConnectionException('timeout'));
        $this->actingAs($admin)->post(route('italian-orders.refund', $order), ['amount' => '10', 'version' => 1])->assertSessionHasErrors('refund');
        $this->assertSame('creating', $order->refunds()->first()->status);
        $gateway->shouldReceive('createRefund')->once()->withArgs(fn ($p, $k) => $p === $payload && $k === $key)->andReturn($this->refundResult(1000));
        $this->post(route('italian-orders.refund', $order), ['amount' => '10', 'version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, $order->refunds()->count());
    }

    public function test_pending_refund_reserves_amount_and_sync_is_idempotent(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['is_admin' => true]);
        $gateway = $this->gateway($order);
        $gateway->shouldReceive('listRefunds')->once()->andReturn([]);
        $gateway->shouldReceive('createRefund')->once()->andReturn($this->refundResult(10000, 're_test_one', 'pending'));
        $this->actingAs($admin)->post(route('italian-orders.refund', $order), ['amount' => '100', 'version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $gateway->shouldReceive('listRefunds')->once()->andReturn([$this->refundResult(10000, 're_test_one', 'pending')]);
        $this->post(route('italian-orders.refund', $order), ['amount' => '1', 'version' => 2])->assertSessionHasErrors('amount');
        $gateway->shouldReceive('listRefunds')->twice()->andReturn([$this->refundResult(10000)]);
        $this->post(route('italian-orders.refunds-sync', $order))->assertSessionHasNoErrors();
        $this->post(route('italian-orders.refunds-sync', $order))->assertSessionHasNoErrors();
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(2, $order->events()->count());
    }

    public function test_refund_requires_admin_and_test_order_and_valid_amount(): void
    {
        $order = $this->order();
        $url = route('italian-orders.refund', $order);
        $this->post($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['-1', '1.001', '1e2', ''] as $amount) {
            $this->post($url, ['amount' => $amount, 'version' => 1])->assertSessionHasErrors('amount');
        }
        $order->update(['is_test' => false]);
        $this->post($url, ['amount' => '1', 'version' => 1])->assertSessionHasErrors('refund');
        $this->assertSame(0, $order->refunds()->count());
    }

    public function test_refunds_created_in_stripe_dashboard_are_imported(): void
    {
        $order = $this->order();
        $gateway = $this->gateway($order);
        $result = $this->refundResult(2500);
        $result['metadata'] = [];
        $gateway->shouldReceive('listRefunds')->twice()->andReturn([$result]);
        app(ItalianOrderRefunds::class)->synchronize($order);
        app(ItalianOrderRefunds::class)->synchronize($order);
        $this->assertSame(1, $order->refunds()->count());
        $this->assertSame(1, $order->events()->count());
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
    }

    public function test_signed_refund_webhook_updates_once_and_invalid_signature_is_rejected(): void
    {
        $order = $this->order();
        config(['stripe.webhook_secret' => 'whsec_refund_test']);
        $gateway = $this->gateway($order);
        $refund = $this->refundResult(1000);
        $refund['metadata'] = [];
        $gateway->shouldReceive('listRefunds')->twice()->andReturn([$refund]);
        $body = json_encode(['id' => 'evt_refund_test', 'object' => 'event', 'type' => 'refund.updated', 'livemode' => false, 'data' => ['object' => $refund]]);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_refund_test');
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'invalid', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400);
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        }
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
        $this->assertSame(1, $order->events()->count());
    }

    public function test_failed_pending_refund_releases_reserved_amount(): void
    {
        $order = $this->order();
        $gateway = $this->gateway($order);
        $gateway->shouldReceive('listRefunds')->once()->andReturn([]);
        $gateway->shouldReceive('createRefund')->once()->andReturn($this->refundResult(1000, 're_test_one', 'pending'));
        $admin = User::factory()->create(['is_admin' => true]);
        app(ItalianOrderRefunds::class)->refund($order, 1000, 1, $admin->id);
        $gateway->shouldReceive('listRefunds')->once()->andReturn([$this->refundResult(1000, 're_test_one', 'failed')]);
        app(ItalianOrderRefunds::class)->synchronize($order);
        $this->assertSame('failed', $order->refunds()->first()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(0, (int) $order->refunds()->whereNotIn('status', ['failed', 'canceled'])->sum('amount'));
    }
}
