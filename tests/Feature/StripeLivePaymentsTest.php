<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\ItalianOrder;
use App\Models\User;
use App\Services\ItalianCheckout;
use App\Services\ItalianOrderRefunds;
use App\Services\StripeGateway;
use App\Services\StripePayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class StripeLivePaymentsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['italian_checkout.enabled' => true, 'stripe.mode' => 'live',
            'stripe.key' => 'pk_live_fake', 'stripe.secret' => 'sk_live_fake',
            'stripe.webhook_secret' => 'whsec_live_fake', 'italian_checkout.mail_enabled' => false]);
        $this->gateway = $this->mock(StripeGateway::class);
    }

    private function order(bool $test = false): ItalianOrder
    {
        return ItalianOrder::create([
            'checkout_token' => (string) Str::uuid(), 'is_test' => $test,
            'customer_name' => 'Maria Rossi', 'email' => 'maria@example.test',
            'shipping_address' => ['country' => 'IT'], 'subtotal_amount' => 10000,
            'total_amount' => 10000, 'payment_status' => 'pending', 'fulfillment_status' => 'pending',
        ])->refresh();
    }

    private function reserve(ItalianOrder $order): array
    {
        $this->gateway->shouldReceive('createSession')->once()->andReturnUsing(function ($payload) {
            $this->assertSame(10000, $payload['line_items'][0]['price_data']['unit_amount']);
            $this->assertSame('https://www.autoradioitaliano.it/return', $payload['return_url']);

            return ['id' => 'cs_live_one', 'object' => 'checkout.session', 'livemode' => true,
                'mode' => 'payment', 'status' => 'open', 'payment_status' => 'unpaid', 'currency' => 'eur',
                'amount_total' => 10000, 'metadata' => $payload['metadata'],
                'client_reference_id' => $payload['client_reference_id'], 'client_secret' => 'private_session'];
        });

        return app(StripePayments::class)->session($order, 'https://www.autoradioitaliano.it/return');
    }

    private function webhook(array $object, bool $live = true, string $secret = 'whsec_live_fake')
    {
        $body = json_encode(['id' => 'evt_live_one', 'object' => 'event', 'livemode' => $live,
            'type' => 'checkout.session.completed', 'data' => ['object' => $object]]);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$body, $secret);

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t=$time,v1=$signature",
        ], $body);
    }

    public function test_live_checkout_creates_real_order_and_rejects_foreign_shipping(): void
    {
        $product = ConfiguratorProduct::create(['handle' => 'camera', 'category' => 'camera', 'title' => 'Camera', 'price_min' => '100.00']);
        $response = $this->post('/checkout/italiano', ['items' => [['type' => 'product', 'id' => $product->id, 'quantity' => 1]]])->assertRedirect();
        $token = basename($response->headers->get('Location'));
        $this->get('/checkout/italiano/'.$token)->assertInertia(fn (Assert $page) => $page->where('isTest', false));
        $data = ['first_name' => 'Maria', 'last_name' => 'Rossi', 'email' => 'maria@example.test', 'phone' => '+393331234567',
            'line1' => 'Via Roma 1', 'postal_code' => '00100', 'city' => 'Roma', 'province' => 'RM', 'country' => 'ES',
            'reviewed' => true, 'quote_hash' => session("italian_checkout_drafts.$token.quote_hash"), 'is_test' => true];
        $this->post('/checkout/italiano/'.$token, $data)->assertSessionHasErrors('country');
        $this->assertDatabaseCount('italian_orders', 0);
        $data['country'] = 'IT';
        $this->post('/checkout/italiano/'.$token, $data)->assertSessionHasNoErrors();
        $order = ItalianOrder::sole();
        $this->assertFalse($order->is_test);
        $this->assertSame('IT', $order->shipping_address['country']);
        $this->get('/checkout/italiano/'.$token.'/pagamento')->assertInertia(fn (Assert $page) => $page
            ->where('isTest', false)->where('publishableKey', 'pk_live_fake')->missing('secret'));
    }

    public function test_live_webhook_confirms_once_even_when_new_sales_are_disabled(): void
    {
        $order = $this->order();
        $session = $this->reserve($order);
        $paid = [...$session, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_live_one'];
        config(['italian_checkout.enabled' => false]);
        $this->gateway->shouldReceive('retrieveSession')->twice()->with('cs_live_one')->andReturn($paid);
        $this->webhook($paid)->assertOk();
        $this->webhook($paid)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(1, $order->events()->count());
        $this->assertDatabaseCount('italian_order_emails', 1);
    }

    public function test_live_webhook_rejects_test_events_invalid_signature_and_wrong_totals(): void
    {
        $order = $this->order();
        $session = $this->reserve($order);
        $this->webhook($session, false)->assertStatus(400);
        $this->webhook($session, secret: 'whsec_wrong')->assertStatus(400);
        foreach ([['amount_total' => 1], ['livemode' => false], ['id' => 'cs_test_one'], ['currency' => 'usd']] as $override) {
            $this->gateway->shouldReceive('retrieveSession')->once()->andReturn([...$session, 'status' => 'complete', 'payment_status' => 'paid', ...$override]);
            $this->webhook($session)->assertStatus(400);
        }
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertDatabaseCount('italian_order_emails', 0);
    }

    public function test_old_test_order_cannot_be_charged_with_live_credentials(): void
    {
        $order = $this->order(true);
        $this->expectException(\LogicException::class);
        app(StripePayments::class)->session($order, 'https://www.autoradioitaliano.it/return');
    }

    public function test_live_return_url_must_be_https_on_italian_domain(): void
    {
        $order = $this->order();
        foreach (['http://www.autoradioitaliano.it/return', 'https://other.example/return'] as $url) {
            try {
                app(StripePayments::class)->session($order, $url);
                $this->fail('Unsafe URL accepted');
            } catch (\LogicException $exception) {
                $this->assertSame('Invalid live return URL.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('italian_order_payments', 0);
    }

    public function test_production_requires_explicit_live_config_and_italian_host(): void
    {
        $this->app->instance('env', 'production');
        $this->assertTrue(ItalianCheckout::enabled());
        $this->assertTrue(ItalianCheckout::availableForRequest(Request::create('https://www.autoradioitaliano.it')));
        $this->assertTrue(ItalianCheckout::availableForRequest(Request::create('https://config.autoradiocanario.com')));
        config(['stripe.mode' => 'test']);
        $this->assertFalse(ItalianCheckout::enabled());
        config(['stripe.mode' => 'live', 'stripe.secret' => 'sk_test_wrong']);
        $this->assertFalse(ItalianCheckout::enabled());
        config(['stripe.secret' => 'sk_live_fake', 'stripe.webhook_secret' => '']);
        $this->assertFalse(ItalianCheckout::enabled());
        $this->app->instance('env', 'local');
        $this->assertFalse(StripePayments::configured());
    }

    public function test_live_refund_is_idempotent_and_does_not_allow_order_deletion(): void
    {
        $order = $this->order();
        $session = $this->reserve($order);
        $paid = [...$session, 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_live_one'];
        $this->gateway->shouldReceive('retrieveSession')->once()->andReturn($paid);
        $this->webhook($paid)->assertOk();
        $this->gateway->shouldReceive('retrievePaymentIntent')->andReturn(['livemode' => true, 'currency' => 'eur',
            'amount_received' => 10000, 'status' => 'succeeded', 'metadata' => ['italian_order_id' => (string) $order->id]]);
        $refund = ['id' => 're_live_one', 'amount' => 2000, 'status' => 'succeeded', 'currency' => 'eur', 'payment_intent' => 'pi_live_one', 'metadata' => ['italian_refund_id' => '1']];
        $this->gateway->shouldReceive('listRefunds')->once()->andReturn([]);
        $this->gateway->shouldReceive('createRefund')->once()->andReturn($refund);
        $admin = User::factory()->create(['is_admin' => true]);
        app(ItalianOrderRefunds::class)->refund($order->fresh(), 2000, $order->fresh()->version, $admin->id);
        $this->gateway->shouldReceive('listRefunds')->twice()->andReturn([$refund]);
        app(ItalianOrderRefunds::class)->synchronize($order);
        app(ItalianOrderRefunds::class)->synchronize($order);
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
        $this->assertSame(1, $order->refunds()->count());
        $this->actingAs($admin)->delete(route('italian-orders.destroy', $order))->assertForbidden();
    }

    public function test_readiness_command_reports_missing_mail_and_accepts_complete_configuration(): void
    {
        $this->app->instance('env', 'production');
        config(['app.debug' => false, 'app.url' => 'https://www.autoradioitaliano.it',
            'italian_checkout.origin' => 'https://www.autoradioitaliano.it',
            'session.secure' => true, 'mail.default' => 'smtp', 'mail.from.address' => 'shop@example.test']);
        $this->artisan('italian-payments:check')->assertExitCode(1);
        config(['italian_checkout.mail_enabled' => true]);
        $this->artisan('italian-payments:check')->assertExitCode(0);
    }
}
