<?php

namespace Tests\Feature;

use App\Models\ItalianOrder;
use App\Services\ItalianPurchaseEmails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ItalianPurchaseEmailsTest extends TestCase
{
    use RefreshDatabase;

    private function pending(bool $test = false, string $locale = 'it'): int
    {
        $order = ItalianOrder::create(['customer_name' => 'Maria', 'email' => 'maria@example.test',
            'is_test' => $test, 'checkout_locale' => $locale, 'shipping_address' => ['country' => $locale === 'es' ? 'ES' : 'IT'], 'total_amount' => 10000,
            'subtotal_amount' => 10000, 'payment_status' => 'paid', 'paid_at' => now()]);

        return DB::table('italian_order_emails')->insertGetId(['italian_order_id' => $order->id,
            'kind' => 'purchase', 'recipient' => $order->email, 'subject' => 'Conferma acquisto',
            'html' => '<p>Ordine confermato</p>', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['italian_checkout.mail_enabled' => true, 'mail.default' => 'smtp', 'mail.from.address' => 'shop@example.test']);
    }

    public function test_live_email_is_sent_once_and_recorded(): void
    {
        $id = $this->pending();
        Mail::shouldReceive('html')->once()->andReturnUsing(function ($html, $callback) {
            $message = new Message(new Email);
            $callback($message);
            $mime = $message->getSymfonyMessage();
            $this->assertSame('maria@example.test', $mime->getTo()[0]->getAddress());
            $this->assertSame('Autoradio Italiano', $mime->getFrom()[0]->getName());
            $this->assertSame('Conferma acquisto', $mime->getSubject());
            $this->assertNotNull($mime->getHeaders()->get('Message-ID'));

            return new \stdClass;
        });
        app(ItalianPurchaseEmails::class)->prepare($id);
        app(ItalianPurchaseEmails::class)->prepare($id);
        $this->assertDatabaseHas('italian_order_emails', ['id' => $id, 'status' => 'sent']);
    }

    public function test_purchase_template_uses_the_italian_dark_brand_style(): void
    {
        $order = ItalianOrder::create([
            'customer_name' => 'Maria',
            'email' => 'maria@example.test',
            'shipping_address' => ['line1' => 'Via Roma 1', 'postal_code' => '00100', 'city' => 'Roma', 'country' => 'IT'],
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);
        $order->items()->create([
            'product_handle' => 'radio-test', 'title' => 'Autoradio', 'quantity' => 1,
            'unit_amount' => 10000, 'total_amount' => 10000,
        ]);

        $html = (new \App\Mail\ItalianPurchaseConfirmation($order->load('items'), preview: true))->render();

        $this->assertStringContainsString('background:#121212', $html);
        $this->assertStringContainsString('color:#f5c400', $html);
        $this->assertStringContainsString('/images/logo-it.png', $html);
        $this->assertStringContainsString('EMAIL DI PROVA', $html);
    }

    public function test_spanish_purchase_email_includes_import_costs_and_canario_branding(): void
    {
        $order = ItalianOrder::create([
            'checkout_locale' => 'es',
            'customer_name' => 'María',
            'email' => 'maria@example.test',
            'shipping_address' => ['line1' => 'Calle Mayor 1', 'postal_code' => '35001', 'city' => 'Las Palmas', 'province' => 'Las Palmas', 'country' => 'ES'],
            'subtotal_amount' => 10000,
            'import_amount' => 3000,
            'total_amount' => 13000,
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);
        $order->items()->create([
            'product_handle' => 'radio-test', 'title' => 'Autorradio', 'quantity' => 2,
            'unit_amount' => 5000, 'total_amount' => 10000,
            'import_unit_amount' => 1500, 'import_total_amount' => 3000,
        ]);
        $mail = new \App\Mail\ItalianPurchaseConfirmation($order->load('items'), preview: true);
        $html = $mail->render();

        $this->assertStringStartsWith('[PRUEBA] Confirmación de compra ES-', $mail->envelope()->subject);
        $this->assertStringContainsString('Autoradio Canario', $mail->envelope()->subject);
        $this->assertStringContainsString('/images/logo.png', $html);
        $this->assertStringContainsString('Costes de importación', $html);
        $this->assertStringContainsString('15,00 € × 2', $html);
        $this->assertStringContainsString('130,00 €', $html);
    }

    public function test_spanish_live_email_uses_the_canario_sender_name(): void
    {
        $id = $this->pending(locale: 'es');
        Mail::shouldReceive('html')->once()->andReturnUsing(function ($html, $callback) {
            $message = new Message(new Email);
            $callback($message);
            $this->assertSame('Autoradio Canario', $message->getSymfonyMessage()->getFrom()[0]->getName());

            return new \stdClass;
        });

        app(ItalianPurchaseEmails::class)->prepare($id);

        $this->assertDatabaseHas('italian_order_emails', ['id' => $id, 'status' => 'sent']);
    }

    public function test_transport_failure_keeps_email_pending_and_retry_succeeds(): void
    {
        $id = $this->pending();
        Mail::shouldReceive('html')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        try {
            app(ItalianPurchaseEmails::class)->prepare($id);
            $this->fail('Expected delivery failure');
        } catch (\RuntimeException $exception) {
            $this->assertDatabaseHas('italian_order_emails', ['id' => $id, 'status' => 'pending']);
        }
        Mail::shouldReceive('html')->once()->andReturn(new \stdClass);
        app(ItalianPurchaseEmails::class)->prepare($id);
        $this->assertDatabaseHas('italian_order_emails', ['id' => $id, 'status' => 'sent']);
    }

    public function test_test_order_stays_local_even_with_live_smtp_enabled(): void
    {
        Storage::fake('local');
        Mail::shouldReceive('html')->never();
        $id = $this->pending(true);
        app(ItalianPurchaseEmails::class)->prepare($id);
        $this->assertDatabaseHas('italian_order_emails', ['id' => $id, 'status' => 'preview']);
        Storage::disk('local')->assertExists('italian-order-emails/'.$id.'.eml');
    }

    public function test_log_transport_cannot_mark_real_confirmation_as_sent(): void
    {
        config(['mail.default' => 'log']);
        Mail::shouldReceive('html')->never();
        $id = $this->pending();
        $this->assertFalse(ItalianPurchaseEmails::configured());
        $this->expectException(\LogicException::class);
        app(ItalianPurchaseEmails::class)->prepare($id);
    }

    public function test_real_order_cannot_send_from_local_environment(): void
    {
        $id = $this->pending();
        $this->app->instance('env', 'local');
        Mail::shouldReceive('html')->never();
        app(ItalianPurchaseEmails::class)->prepare($id);
        $this->assertDatabaseHas('italian_order_emails', ['id' => $id, 'status' => 'pending']);
    }
}
