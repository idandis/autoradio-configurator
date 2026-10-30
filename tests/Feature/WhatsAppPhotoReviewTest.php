<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\User;
use App\Services\WhatsAppAssistantSettings;
use App\Services\WhatsAppChatControl;
use App\Services\WhatsAppTestBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class WhatsAppPhotoReviewTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDir;
    private array $polled = [];
    private string $mediaUrl = 'http://localhost:3000/api/files/customer-photo.png';
    private string $bytes;
    private int $mailCount = 0;
    private string $mailBody = '';
    private ?Email $email = null;
    private bool $mailFails = false;
    private ConfiguratorProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->cacheDir = sys_get_temp_dir().'/autoradio-photo-'.bin2hex(random_bytes(6));
        config(['cache.stores.file.path' => $this->cacheDir, 'waha.url' => 'http://127.0.0.1:3000',
            'waha.session' => 'default', 'waha.key' => 'test-key', 'waha.test_number' => '34611111111',
            'waha.test_enabled' => true, 'services.openai.api_key' => 'test-key', 'mail.notification_to' => 'admin@example.test']);
        Storage::fake('local');
        Sleep::fake();
        app(WhatsAppAssistantSettings::class)->save(['language' => 'it']);
        $this->bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jp1sAAAAASUVORK5CYII=');
        $this->product = ConfiguratorProduct::create(['handle' => 'mercedes-sl-photo', 'category' => 'screen',
            'title' => 'Radio Mercedes SL', 'title_it' => 'Autoradio Mercedes SL', 'brand' => 'MERCEDES', 'model' => 'SL', 'year_from' => 2008, 'year_to' => 2012]);
        ConfiguratorProduct::create(['handle' => 'other-mercedes-radio', 'category' => 'screen',
            'title' => 'Altra radio', 'brand' => 'MERCEDES', 'model' => 'SL', 'year_from' => 2008, 'year_to' => 2012]);
        Mail::shouldReceive('mailer')->with('smtp')->andReturnSelf();
        Mail::shouldReceive('raw')->andReturnUsing(function ($body, $callback) {
            $this->mailCount++;
            $this->mailBody = $body;
            $message = new Message(new Email);
            $callback($message);
            $this->email = $message->getSymfonyMessage();
            if ($this->mailFails) throw new RuntimeException('SMTP unavailable');

            return new \stdClass;
        });
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/sessions/default') return Http::response(['status' => 'WORKING', 'me' => ['id' => '34999999999@c.us']]);
            if ($path === '/api/default/lids/pn/34611111111') return Http::response(['pn' => '34611111111@c.us', 'lid' => '12345678@lid']);
            if (str_contains($path, '/messages/')) return Http::response($this->photoMessage() + ['media' => ['mimetype' => 'image/png', 'url' => $this->mediaUrl]]);
            if (str_ends_with($path, '/messages')) return Http::response($this->polled);
            if ($path === '/api/files/customer-photo.png') return Http::response($this->bytes, 200, ['Content-Type' => 'image/png']);
            if ($path === '/api/sendText') return Http::response(['id' => 'outgoing-photo-reply']);
            throw new RuntimeException('Unexpected request: '.$path);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cacheDir);
        parent::tearDown();
    }

    private function photoMessage(array $changes = []): array
    {
        return array_replace(['id' => 'customer-photo', 'fromMe' => false, 'from' => '12345678@lid',
            'timestamp' => time(), 'hasMedia' => true, 'body' => '', 'type' => 'image'], $changes);
    }

    private function textMessage(string $text): array
    {
        return ['id' => 'customer-text', 'fromMe' => false, 'from' => '12345678@lid',
            'timestamp' => time(), 'hasMedia' => false, 'body' => $text];
    }

    private function knownVehicle(): void
    {
        $suffix = app(WhatsAppAssistantSettings::class)->suffix();
        Cache::store('file')->put('waha-test-vehicle:'.$suffix, ['brand' => 'MERCEDES', 'model' => 'SL', 'year' => 2011]);
        $v = $this->product->variants()->create(['title' => '8G 128G', 'shopify_variant_id' => '123', 'price' => 400]);
        Cache::store('file')->put('waha-test-quote:'.$suffix, ['product' => $this->product->handle, 'variant_id' => $v->id]);
    }

    public function test_photo_is_emailed_with_vehicle_selected_radio_and_variant_link_without_ai_then_chat_pauses(): void
    {
        $this->knownVehicle();
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->photoMessage(), time() - 1));
        $this->assertSame(1, $this->mailCount);
        $this->assertStringContainsString('MERCEDES SL 2011', $this->mailBody);
        $this->assertStringContainsString('Autoradio Mercedes SL', $this->mailBody);
        $this->assertStringContainsString('/products/mercedes-sl-photo?variant=123', $this->mailBody);
        $this->assertStringNotContainsString('/products/other-mercedes-radio', $this->mailBody);
        $this->assertCount(1, $this->email->getAttachments());
        $this->assertSame('admin@example.test', $this->email->getTo()[0]->getAddress());
        $this->assertStringContainsString('foto per verifica compatibilità', $this->email->getSubject());
        $this->assertTrue(app(WhatsAppChatControl::class)->contact('34611111111')->paused);
        $this->assertSame([], Storage::disk('local')->files('waha/photos'));
        $this->assertFalse($bot->reply($this->photoMessage(), time() - 1));
        $this->assertSame(1, $this->mailCount);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_preview_collects_missing_vehicle_fields_in_all_languages_and_never_sends_email(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['it', 'es', 'en'] as $locale) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
            $file = UploadedFile::fake()->createWithContent('dashboard.png', $this->bytes);
            $this->post('/dashboard/whatsapp/preview', ['photo' => $file], ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('mode', 'vehicle_details');
            $this->assertCount(1, session('whatsapp_preview.photos'));
            $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Mercedes'])->assertOk()->assertJsonPath('mode', 'vehicle_details');
            $this->postJson('/dashboard/whatsapp/preview', ['message' => 'SL'])->assertOk()->assertJsonPath('mode', 'vehicle_details');
            $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => '2011'])->assertOk()->assertJsonPath('mode', 'photo_review');
            $this->assertStringNotContainsString('compatibile confermata', $r->json('text'));
            $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Hello'])->assertOk()->assertJsonPath('mode', 'operator_waiting');
            $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
            $this->assertSame([], Storage::disk('local')->files('waha/photos'));
        }
        $this->assertSame(0, $this->mailCount);
        Http::assertNothingSent();
    }

    public function test_preview_photo_email_requires_explicit_click_and_is_not_duplicated(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/dashboard/whatsapp/preview', ['message' => 'Mercedes SL 2011',
            'photo' => UploadedFile::fake()->createWithContent('dashboard.png', $this->bytes)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('mode', 'photo_review');
        $this->assertSame(0, $this->mailCount);
        $this->postJson('/dashboard/whatsapp/preview/photo-email')->assertOk()->assertJsonPath('sent', true);
        $this->assertSame(1, $this->mailCount);
        $this->assertCount(1, $this->email->getAttachments());
        $this->assertSame('admin@example.test', $this->email->getTo()[0]->getAddress());
        $this->assertStringStartsWith('[PROVA]', $this->email->getSubject());
        $this->assertStringContainsString('MERCEDES SL 2011', $this->mailBody);
        $this->assertStringContainsString('/products/mercedes-sl-photo', $this->mailBody);
        $this->assertTrue(session('whatsapp_preview.photo_email_sent'));
        $this->assertSame([], Storage::disk('local')->files('waha/photos'));
        $this->postJson('/dashboard/whatsapp/preview/photo-email')->assertOk();
        $this->assertSame(1, $this->mailCount);
        Http::assertNothingSent();
    }

    public function test_preview_photo_email_failure_retains_attachment_for_retry(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/dashboard/whatsapp/preview', ['message' => 'Mercedes SL 2011',
            'photo' => UploadedFile::fake()->createWithContent('dashboard.png', $this->bytes)], ['Accept' => 'application/json'])->assertOk();
        $this->mailFails = true;
        $this->postJson('/dashboard/whatsapp/preview/photo-email')->assertStatus(503);
        $this->assertFalse(session('whatsapp_preview.photo_email_sent', false));
        $this->assertCount(1, Storage::disk('local')->files('waha/photos'));
        $this->mailFails = false;
        $this->postJson('/dashboard/whatsapp/preview/photo-email')->assertOk();
        $this->assertSame(2, $this->mailCount);
    }

    public function test_preview_photo_email_requires_admin_and_completed_photo_simulation(): void
    {
        $this->postJson('/dashboard/whatsapp/preview/photo-email')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->postJson('/dashboard/whatsapp/preview/photo-email')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/dashboard/whatsapp/preview/photo-email')->assertStatus(422);
        $this->post('/dashboard/whatsapp/preview', ['photo' => UploadedFile::fake()->createWithContent('dashboard.png', $this->bytes)], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/dashboard/whatsapp/preview/photo-email')->assertStatus(422);
        $this->assertSame(0, $this->mailCount);
    }

    public function test_photo_reply_lists_vehicle_radios_before_operator_notice_in_all_languages_without_ai(): void
    {
        $this->product->variants()->create(['title' => '8core 3G 32G', 'price' => 400, 'shopify_variant_id' => 'min-eight']);
        $this->product->variants()->create(['title' => '4core 4G 64G', 'price' => 300, 'shopify_variant_id' => 'four']);
        foreach (['it' => 'Foto ricevuta.', 'es' => 'Foto recibida.', 'en' => 'Photo received.'] as $locale => $notice) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale, 'max_products' => 1]);
            $r = app(\App\Services\WhatsAppPhotoReview::class)->step('Mercedes SL 2011 con camera', [], [], [], '34611111111', [], $locale);
            $this->assertSame('photo_review', $r['mode']);
            $this->assertStringContainsString('/products/mercedes-sl-photo?variant=min-eight', $r['text']);
            $this->assertStringNotContainsString('€', $r['text']);
            $this->assertStringNotContainsString('?variant=four', $r['text']);
            $this->assertStringContainsString('/products/other-mercedes-radio', $r['text']);
            $this->assertTrue(strpos($r['text'], '/products/mercedes-sl-photo') < strpos($r['text'], $notice));
            $this->assertCount(2, $r['alert']['products']);
            $this->assertStringContainsString($notice, $r['text']);
        }
        $this->assertSame(0, $this->mailCount);
        Http::assertNothingSent();
    }

    public function test_recovery_combines_a_photo_with_the_following_vehicle_message(): void
    {
        $this->polled = [$this->photoMessage(), $this->textMessage('Mercedes SL 2011')];
        $result = app(WhatsAppTestBot::class)->recoverUnanswered();
        $this->assertSame('replied', $result['state']);
        $this->assertSame(1, $this->mailCount);
        $this->assertStringContainsString('MERCEDES SL 2011', $this->mailBody);
        $this->assertCount(1, $this->email->getAttachments());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_photo_from_other_contact_or_group_and_unsupported_media_are_ignored(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        foreach ([['from' => '39999999999@c.us'], ['from' => '12345678@g.us'], ['fromMe' => true],
            ['type' => 'video'], ['type' => 'document', 'media' => ['mimetype' => 'application/pdf']]] as $changes) {
            $this->assertFalse($bot->reply($this->photoMessage($changes), time() - 1));
        }
        $this->assertSame(0, $this->mailCount);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/messages/') || str_contains($r->url(), '/api/files/') || str_ends_with($r->url(), '/api/sendText'));
    }

    public function test_untrusted_media_url_is_not_fetched_and_client_is_asked_to_resend_without_ai(): void
    {
        $this->mediaUrl = 'https://attacker.example/photo.png';
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->photoMessage(), time() - 1));
        $this->assertSame(0, $this->mailCount);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'attacker.example') || str_contains($r->url(), 'openai.com'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], 'reinviarla'));
    }

    public function test_mail_failure_does_not_claim_success_and_keeps_photo_for_operator(): void
    {
        $this->knownVehicle();
        $this->mailFails = true;
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->photoMessage(), time() - 1));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], 'non riesco a inoltrare') && ! str_contains($r['text'], 'ti ricontatterà'));
        $this->assertCount(1, Storage::disk('local')->files('waha/photos'));
        $this->assertTrue(app(WhatsAppChatControl::class)->contact('34611111111')->paused);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_no_matching_radio_is_reported_to_admin_without_inventing_a_link(): void
    {
        $suffix = app(WhatsAppAssistantSettings::class)->suffix();
        Cache::store('file')->put('waha-test-vehicle:'.$suffix, ['brand' => 'SAAB', 'model' => '95', 'year' => 2004]);
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->photoMessage(), time() - 1));
        $this->assertStringContainsString('SAAB 95 2004', $this->mailBody);
        $this->assertStringContainsString('Nessuna radio individuata', $this->mailBody);
        $this->assertStringNotContainsString('/products/', $this->mailBody);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_corrupt_photo_is_not_emailed_and_caption_is_not_sent_to_ai(): void
    {
        $this->bytes = 'not an image';
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->photoMessage(['body' => 'Mercedes SL 2011']), time() - 1));
        $this->assertSame(0, $this->mailCount);
        $this->assertSame([], Storage::disk('local')->files('waha/photos'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], 'reinviarla'));
    }

    public function test_real_chat_keeps_photo_until_missing_year_arrives_then_emails_it(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->photoMessage(['body' => 'Mercedes SL']), time() - 1));
        $this->assertSame(0, $this->mailCount);
        $this->assertCount(1, Storage::disk('local')->files('waha/photos'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], 'Di che anno'));
        $this->assertTrue($bot->reply($this->textMessage('2011'), time() - 1));
        $this->assertSame(1, $this->mailCount);
        $this->assertStringContainsString('MERCEDES SL 2011', $this->mailBody);
        $this->assertCount(1, $this->email->getAttachments());
        $this->assertSame([], Storage::disk('local')->files('waha/photos'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_photo_review_keeps_explicitly_selected_universal_radio(): void
    {
        $this->knownVehicle();
        $p = ConfiguratorProduct::create(['handle' => 'selected-universal', 'category' => 'screen', 'title' => 'Autoradio universale 1DIN',
            'model' => 'Universal', 'meta' => ['din' => '1DIN']]);
        $v = $p->variants()->create(['title' => 'CarPlay', 'shopify_variant_id' => '999', 'price' => 200]);
        Cache::store('file')->put('waha-test-quote:'.app(WhatsAppAssistantSettings::class)->suffix(), [
            'catalog_mode' => 'universal', 'product' => $p->handle, 'variant_id' => $v->id,
        ]);
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->photoMessage(), time() - 1));
        $this->assertStringContainsString('/products/selected-universal?variant=999', $this->mailBody);
        $this->assertStringNotContainsString('/products/mercedes-sl-photo', $this->mailBody);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_preview_accepts_ogg_without_text_and_keeps_transcript_in_chat(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->mock(\App\Services\WhatsAppVoice::class)->shouldReceive('upload')->once()->andReturn('Tengo un Mercedes SL del 2011');
        $r = $this->post('/dashboard/whatsapp/preview', ['audio' => UploadedFile::fake()->create('voice.ogg', 10, 'audio/ogg')], ['Accept' => 'application/json']);
        $r->assertOk()->assertJsonPath('mode', 'offer')
            ->assertJsonPath('chat.0.question', "[Audio trascritto]\nTengo un Mercedes SL del 2011");
        $this->assertSame('Tengo un Mercedes SL del 2011', session('whatsapp_preview.history')[0]['content']);
        $this->assertSame(2011, session('whatsapp_preview.vehicle.year'));
        $this->assertSame(0, $this->mailCount);
        Http::assertNothingSent();
    }

    public function test_tax_answer_for_unmatched_vehicle_also_emails_operator(): void
    {
        ConfiguratorProduct::create(['handle' => 'vw-tiguan', 'title' => 'Radio VW', 'category' => 'screen',
            'brand' => 'VOLKSWAGEN', 'model' => 'Tiguan', 'year_from' => 2016, 'year_to' => 2020]);
        app(WhatsAppAssistantSettings::class)->save(['language' => 'es']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $text = 'Tengo un Volkswagen T1 del 2019 y quiero instalar CarPlay. Estoy en Canarias, el IVA se deduce y cambiaría el precio, ¿verdad?';
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => $text])->assertOk()->assertJsonPath('mode', 'handoff');
        $this->assertStringContainsString('IGIC para Canarias', $r->json('text'));
        $this->assertStringContainsString('Un operador comprobará', $r->json('text'));
        $this->assertSame(1, $this->mailCount);
        $this->assertStringContainsString('VOLKSWAGEN T1 2019', $this->mailBody);
        $this->assertStringContainsString($text, $this->mailBody);
        $this->assertNotEmpty(session('whatsapp_preview.handoff_until'));
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Gracias'])->assertOk()->assertJsonPath('mode', 'operator_waiting');
        $this->assertSame(1, $this->mailCount);
        Http::assertNothingSent();
    }

    public function test_preview_unclear_audio_keeps_previous_conversation(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $state = ['history' => [['role' => 'user', 'content' => 'Hola']], 'last_activity' => now()->timestamp];
        $this->withSession(['whatsapp_preview' => $state]);
        $this->mock(\App\Services\WhatsAppVoice::class)->shouldReceive('upload')->once()->andThrow(new RuntimeException('Unclear'));
        $this->post('/dashboard/whatsapp/preview', ['audio' => UploadedFile::fake()->create('voice.ogg', 10, 'audio/ogg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'Non riesco a trascrivere l’audio. Usa un file chiaro di massimo 2 minuti e verifica che Whisper e FFmpeg siano disponibili sul Mac.');
        $this->assertSame($state, session('whatsapp_preview'));
        Http::assertNothingSent();
    }

    public function test_preview_rejects_invalid_or_oversized_audio_before_transcription(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->mock(\App\Services\WhatsAppVoice::class)->shouldNotReceive('upload');
        foreach ([UploadedFile::fake()->create('voice.ogg', 12289, 'audio/ogg'),
            UploadedFile::fake()->create('voice.ogg', 10, 'application/pdf')] as $file) {
            $this->post('/dashboard/whatsapp/preview', ['audio' => $file], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('audio');
        }
    }

    public function test_preview_rejects_non_photo_attachments(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/dashboard/whatsapp/preview', ['photo' => UploadedFile::fake()->createWithContent('file.pdf', '%PDF-1.4')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame(0, $this->mailCount);
        Http::assertNothingSent();
    }

    public function test_paused_chat_does_not_download_or_forward_new_photos(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $control = app(WhatsAppChatControl::class);
        $control->pause($control->contact('34611111111'), 'dashboard');
        $this->assertFalse($bot->reply($this->photoMessage(), time() - 1));
        $this->assertSame(0, $this->mailCount);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/messages/') || str_contains($r->url(), '/api/files/'));
    }
}
