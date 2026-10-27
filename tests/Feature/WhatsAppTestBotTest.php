<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Services\WhatsAppAssistantSettings;
use App\Services\WhatsAppCatalogSearch;
use App\Services\WhatsAppProductOffer;
use App\Services\WhatsAppQuotePdf;
use App\Services\WhatsAppSessionConnection;
use App\Services\WhatsAppTestBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class WhatsAppTestBotTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private bool $lidMatches = true;

    private bool $aiComplete = true;

    private array $aiPayloads = [];

    private ?\Closure $onAi = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partialMock(WhatsAppQuotePdf::class)->shouldReceive('render')->andReturnUsing(fn ($html) => "%PDF-1.4\n".$html."\n%%EOF");
        Sleep::fake();
        Storage::fake('local');
        $this->app['env'] = 'local';
        $this->dir = sys_get_temp_dir().'/autoradio-waha-test-'.bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->dir);
        File::ensureDirectoryExists(storage_path('app/private/waha'));
        config([
            'waha.url' => 'http://127.0.0.1:3000', 'waha.key' => 'test-key',
            'waha.session' => 'default', 'waha.test_number' => '34611509809', 'waha.test_enabled' => true,
            'services.openai.api_key' => 'test-openai-key', 'services.openai.text_model' => 'gpt-5-mini',
            'cache.stores.file.path' => $this->dir.'/cache',
        ]);
        Mail::shouldReceive('mailer')->with('smtp')->andReturnSelf();
        Mail::shouldReceive('raw')->andReturn(new \stdClass)->byDefault();
        app(WhatsAppAssistantSettings::class)->save(['fallback_language' => 'it']);
        Http::preventStrayRequests();
        Http::fake([
            '*/api/sessions/default' => Http::response(['status' => 'WORKING', 'me' => ['id' => '34999999999@c.us']]),
            '*/api/default/lids/pn/34611509809' => fn () => Http::response(['pn' => $this->lidMatches ? '34611509809@c.us' : '34988888888@c.us', 'lid' => '123456789@lid']),
            'api.openai.com/v1/responses' => function () {
                if ($this->onAi) {
                    ($this->onAi)();
                }

                if ($this->aiPayloads) {
                    return Http::response(array_shift($this->aiPayloads));
                }

                return Http::response([
                    'status' => $this->aiComplete ? 'completed' : 'incomplete', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['answer' => 'Ciao! Quale modello e anno?', 'unknown' => [], 'needs_operator' => false])]]]],
                ]);
            },
            '*/api/sendText' => Http::response(['id' => 'outgoing-id']),
            '*/api/sendFile' => Http::response(['id' => 'outgoing-document-id']),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function message(array $overrides = []): array
    {
        return array_replace([
            'id' => 'incoming-id', 'from' => '123456789@lid', 'fromMe' => false,
            'timestamp' => time(), 'hasMedia' => false, 'body' => 'Ciao, ho una Ford Focus 2012',
        ], $overrides);
    }

    private function introducedVehicle(): void
    {
        ConfiguratorProduct::create(['handle' => 'introduced-radio', 'category' => 'screen', 'title' => 'Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009, 'price_min' => 300]);
        $settings = app(WhatsAppAssistantSettings::class);
        $vehicle = ['brand' => 'AUDI', 'model' => 'A4', 'year' => 2001];
        Cache::store('file')->put('waha-test-vehicle:'.$settings->suffix(), $vehicle, now()->addHours(12));
        Cache::store('file')->put('waha-test-offer:'.$settings->suffix(), hash('sha256', json_encode($vehicle)), now()->addHours(12));
    }

    public function test_audi_a4_2001_returns_specific_screens_and_excludes_other_years_and_models(): void
    {
        foreach ([
            ['a4-7', 'Radio Audi A4 B6 B7 7 pollici', 'A4', 2000, 2009],
            ['a4-9', 'Radio Audi A4 B6 B7 9 pollici', 'A4', 2000, 2009],
            ['a4-new', 'Radio Audi A4 B8', 'A4', 2009, 2016],
            ['a1', 'Radio Audi A1', 'A1', 2000, 2009],
            ['multi', 'Radio multi-marca', '1:A4 | 2:Focus', 2000, 2009],
        ] as [$handle, $title, $model, $from, $to]) {
            ConfiguratorProduct::create([
                'handle' => $handle, 'title' => $title, 'category' => 'screen',
                'brand' => $handle === 'multi' ? 'AUDI | FORD' : 'AUDI',
                'model' => $model, 'year_from' => $from, 'year_to' => $to,
            ]);
        }
        $search = app(WhatsAppCatalogSearch::class);
        $r = $search->search(['Ciao ho una Audi a4 2001 avete una radio specific?']);
        $this->assertSame(['brand' => 'AUDI', 'model' => 'A4', 'year' => 2001], $r['vehicle']);
        $this->assertSame(3, $r['matching_specific_screens']);
        $this->assertEqualsCanonicalizing(['Radio Audi A4 B6 B7 7 pollici', 'Radio Audi A4 B6 B7 9 pollici', 'Radio multi-marca'], array_column($r['products'], 'title'));
        $continued = $search->search(['Quale mi consigli?', 'Italia', 'Ha CarPlay?'], $r['vehicle']);
        $this->assertSame($r['vehicle'], $continued['vehicle']);
        $this->assertSame(3, $continued['matching_specific_screens']);
        $changed = $search->search(['Invece Audi A1'], $r['vehicle']);
        $this->assertSame(['brand' => 'AUDI', 'model' => 'A1'], $changed['vehicle']);
    }

    public function test_only_verified_test_contact_gets_one_reply_with_catalog_context(): void
    {
        ConfiguratorProduct::create([
            'handle' => 'ford-focus', 'category' => 'screen', 'title' => 'Radio Ford Focus 2012',
            'brand' => 'FORD', 'model' => 'Focus', 'body_html' => '<p>Android 13</p>',
            'year_from' => 2010, 'year_to' => 2018,
        ]);
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->message(), time() - 1));
        $this->assertFalse($bot->reply($this->message(), time() - 1));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText')
            && $r['chatId'] === '34611509809@c.us' && str_contains($r['text'], '/products/ford-focus'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
        Http::assertSentCount(3);
    }

    public function test_specific_screen_is_offered_with_price_and_link_without_calling_ai(): void
    {
        $p = ConfiguratorProduct::create([
            'handle' => 'audi-a4-radio', 'category' => 'screen', 'title' => 'Pantalla 7" Audi A4',
            'title_it' => 'Schermo 7" Audi A4', 'brand' => 'AUDI', 'model' => 'A4',
            'year_from' => 2000, 'year_to' => 2009, 'price_min' => 312,
        ]);
        $p->variants()->create(['title' => 'Base', 'option_value' => '2 GB', 'price' => 312, 'shopify_variant_id' => '12345']);
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->message(['body' => 'Ciao ho Audi A4 2001, avete una radio?']), time() - 1));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.openai.com'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText')
            && str_contains($r['text'], '312 €')
            && str_contains($r['text'], '/products/audi-a4-radio?variant=12345')
            && ! str_contains(str_replace('?variant=12345', '', $r['text']), '?'));
        $this->assertFalse($bot->reply($this->message(['body' => 'Ciao ho Audi A4 2001, avete una radio?']), time() - 1));
        Http::assertSentCount(3);
    }

    public function test_offer_uses_regional_price_when_available_and_checks_all_variants(): void
    {
        $p = ConfiguratorProduct::create([
            'handle' => 'audi-a4-radio', 'category' => 'screen', 'title' => 'Pantalla 7" Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009,
        ]);
        $p->variants()->create(['title' => 'Base', 'price' => 100, 'shopify_variant_id' => '100']);
        foreach (range(1, 7) as $i) {
            $p->variants()->create(['title' => 'Version '.$i, 'price' => 400, 'shopify_variant_id' => (string) (100 + $i),
                'meta' => ['regional_prices' => ['italia' => $i === 7 ? '299,90' : '399,00']]]);
        }
        $r = app(WhatsAppCatalogSearch::class)->search(['Audi A4 2001 Italia']);
        $offer = app(WhatsAppProductOffer::class)->make($r, ['Audi A4 2001 Italia']);
        $this->assertStringContainsString('299,90 €', $offer);
        $this->assertStringContainsString('?variant=107', $offer);
        $this->assertStringNotContainsString('100 €', $offer);
    }

    public function test_other_contacts_groups_media_old_messages_and_self_messages_are_ignored(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        foreach ([
            ['from' => '34988888888@c.us'], ['from' => '888888888@lid'], ['from' => '123@g.us'],
            ['from' => 'status@broadcast'], ['fromMe' => true], ['hasMedia' => true],
            ['body' => ''], ['id' => ''], ['timestamp' => time() - 100], ['location' => ['latitude' => 1]],
        ] as $overrides) {
            $this->assertFalse($bot->reply($this->message($overrides), time() - 1));
        }
        Http::assertSentCount(2);
    }

    public function test_unverified_lid_is_rejected(): void
    {
        $this->lidMatches = false;
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertFalse($bot->reply($this->message(), time() - 1));
        Http::assertSentCount(2);
    }

    public function test_pause_and_disabled_configuration_prevent_sending(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        File::put(storage_path('app/private/waha/PAUSA'), 'stop');
        $this->assertFalse($bot->reply($this->message(), time() - 1));
        Http::assertSentCount(2);
        config(['waha.test_enabled' => false]);
        $this->expectException(RuntimeException::class);
        $bot->reply($this->message(), time() - 1);
    }

    public function test_production_environment_is_rejected(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        app(WhatsAppTestBot::class)->assertEnabled();
    }

    public function test_incomplete_ai_output_is_not_sent_or_retried(): void
    {
        $this->introducedVehicle();
        $this->aiComplete = false;
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        try {
            $bot->reply($this->message(['body' => 'Una informazione tecnica sul funzionamento']), time() - 1);
            $this->fail('Expected incomplete response to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('incompleta', $e->getMessage());
        }
        $this->assertFalse($bot->reply($this->message(['body' => 'Una informazione tecnica sul funzionamento']), time() - 1));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
    }

    public function test_dashboard_pause_and_resume_apply_to_existing_bot_instance(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['enabled' => false]);
        $this->assertFalse($bot->reply($this->message(), time() - 1));
        $settings->save(['enabled' => true]);
        $this->assertTrue($bot->reply($this->message(), time() - 1));
    }

    public function test_recipient_change_during_generation_cancels_the_reply(): void
    {
        $this->introducedVehicle();
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->onAi = fn () => app(WhatsAppAssistantSettings::class)->save(['test_number' => '34988888888']);
        $this->assertFalse($bot->reply($this->message(['body' => 'Una informazione tecnica sul funzionamento']), time() - 1));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
    }

    public function test_reset_during_generation_cancels_the_reply(): void
    {
        $this->introducedVehicle();
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->onAi = fn () => app(WhatsAppAssistantSettings::class)->reset();
        $this->assertFalse($bot->reply($this->message(['body' => 'Una informazione tecnica sul funzionamento']), time() - 1));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
    }

    public function test_handoff_suspends_actual_chat_until_reset(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->message(['body' => 'Vorrei un operatore']), time() - 1));
        $this->assertFalse($bot->reply($this->message(['id' => 'follow-up']), time() - 1));
        app(WhatsAppAssistantSettings::class)->reset();
        $this->assertTrue($bot->reply($this->message(['id' => 'after-reset']), time() - 1));
    }

    public function test_session_restart_during_generation_cancels_the_reply(): void
    {
        $this->introducedVehicle();
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->onAi = fn () => Cache::store('file')->put(
            app(WhatsAppSessionConnection::class)->revisionKey(), 'restart', now()->addMinute(),
        );
        $this->assertFalse($bot->reply($this->message(['body' => 'Una informazione tecnica sul funzionamento']), time() - 1));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
    }

    public function test_delay_precedes_send_and_pause_during_wait_cancels_reply(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        app(WhatsAppAssistantSettings::class)->save(['reply_delay_seconds' => 4]);
        Sleep::whenFakingSleep(function () {
            app(WhatsAppAssistantSettings::class)->save(['enabled' => false]);
        });
        $this->assertFalse($bot->reply($this->message(), time() - 1));
        Sleep::assertSleptTimes(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/sendText'));
    }

    public function test_welcome_uses_customer_greeting_and_restarts_only_after_24_hours_of_inactivity(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        app(WhatsAppAssistantSettings::class)->save(['welcome_messages' => [
            'it' => 'Benvenuto personalizzato.', 'es' => 'Bienvenida personalizada.', 'en' => 'Custom welcome.',
        ]]);
        $this->assertTrue($bot->reply($this->message(['id' => 'welcome-1', 'body' => 'Hola!']), time() - 1));
        $this->travel(23)->hours();
        $this->assertTrue($bot->reply($this->message(['id' => 'welcome-2', 'body' => 'Hola!']), time() - 1));
        $this->travel(2)->hours();
        $this->assertTrue($bot->reply($this->message(['id' => 'welcome-3', 'body' => 'Hola!']), time() - 1));
        $this->travel(24)->hours();
        $this->assertTrue($bot->reply($this->message(['id' => 'welcome-4', 'body' => 'Good evening!']), time() - 1));
        $sent = Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/sendText'))->values();
        $this->assertSame("Hola! Bienvenida personalizada.\n\n¿De qué marca es tu coche?", $sent[0][0]['text']);
        $this->assertStringNotContainsString('Bienvenida', $sent[1][0]['text']);
        $this->assertStringNotContainsString('Bienvenida', $sent[2][0]['text']);
        $this->assertSame("Good evening! Custom welcome.\n\nWhat make is your car?", $sent[3][0]['text']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai.com'));
        $this->travelBack();
    }

    public function test_welcome_precedes_standard_answer_and_does_not_repeat_after_reset(): void
    {
        $this->introducedVehicle();
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['standard_replies' => [['enabled' => true, 'answers' => ['it' => 'Ha Bluetooth.']]]]);
        $this->assertTrue($bot->reply($this->message(['id' => 'first-greeting', 'body' => 'Buongiorno, ha Bluetooth?']), time() - 1));
        $settings->reset();
        $this->assertTrue($bot->reply($this->message(['id' => 'second-greeting', 'body' => 'Buongiorno, ha Bluetooth?']), time() - 1));
        $sent = Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/sendText'))->values();
        $this->assertStringStartsWith('Buongiorno! Benvenuto', $sent[0][0]['text']);
        $this->assertStringEndsWith('Ha Bluetooth.', $sent[0][0]['text']);
        $this->assertSame('Di che marca è la tua auto?', $sent[1][0]['text']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai.com'));
    }

    public function test_radio_proposals_require_make_model_and_year_then_offer_immediately(): void
    {
        ConfiguratorProduct::create([
            'handle' => 'mitsubishi-outlander', 'category' => 'screen', 'title' => 'Radio Mitsubishi Outlander',
            'brand' => 'MITSUBISHI', 'model' => 'Outlander', 'year_from' => 2010, 'year_to' => 2020, 'price_min' => 300,
        ]);
        $bot = app(WhatsAppTestBot::class);
        foreach ([
            'Avete una radio per Mitsubishi Outlander?' => 'Di che anno è la tua auto?',
            'Busco una radio para Mitsubishi Outlander' => '¿De qué año es tu coche?',
            'Looking for a radio for Mitsubishi Outlander' => 'What year is your car?',
            'Radio per Mitsubishi 2015?' => 'Qual è il modello della tua auto?',
        ] as $message => $question) {
            $result = $bot->answer($message);
            $this->assertSame('vehicle_details', $result['mode']);
            $this->assertSame($question, $result['text']);
            $this->assertStringNotContainsString('https://', $result['text']);
        }
        $inferred = $bot->answer('Radio per Outlander 2015?');
        $this->assertSame('offer', $inferred['mode']);
        $this->assertSame('MITSUBISHI', $inferred['context']['vehicle']['brand']);
        $first = $bot->answer('Avete una radio per Mitsubishi Outlander?');
        $result = $bot->answer('2015', [
            ['role' => 'user', 'content' => 'Avete una radio per Mitsubishi Outlander?'],
            ['role' => 'assistant', 'content' => $first['text']],
        ], $first['context']['vehicle']);
        $this->assertSame('offer', $result['mode']);
        $this->assertStringContainsString('300 €', $result['text']);
        $this->assertStringContainsString('/products/mitsubishi-outlander', $result['text']);
        Http::assertNothingSent();
    }

    public function test_missing_radio_compiles_existing_form_and_sends_one_email(): void
    {
        ConfiguratorProduct::create([
            'handle' => 'audi-old', 'category' => 'screen', 'title' => 'Radio Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009,
        ]);
        Mail::shouldReceive('html')->once()->withArgs(fn ($body, $callback) => str_contains($body, 'WhatsApp') && str_contains($body, 'AUDI') && str_contains($body, '2020'));
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        foreach (['Vorrei una radio per Audi A4 2020'] as $i => $body) {
            $this->assertTrue($bot->reply($this->message(['id' => 'missing-'.$i, 'body' => $body]), time() - 1));
        }
        $this->assertDatabaseHas('missing_vehicle_requests', [
            'first_name' => '', 'last_name' => '', 'email' => '',
            'province' => '', 'phone' => '34611509809', 'brand' => 'AUDI', 'model' => 'A4', 'year' => 2020,
        ]);
        $sent = Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/sendText'))->last();
        $this->assertStringEndsWith('Stiamo cercando lo schermo compatibile con il tuo modello. Verrai contattato il prima possibile.', $sent[0]['text']);
        $this->assertTrue($bot->reply($this->message(['id' => 'missing-again', 'body' => 'Radio per Audi A4 2020']), time() - 1));
        $this->assertDatabaseCount('missing_vehicle_requests', 1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_compound_model_in_screen_title_beats_camera_model_and_does_not_report_missing(): void
    {
        ConfiguratorProduct::create([
            'handle' => 'c4-cactus-smeg', 'category' => 'screen',
            'title' => 'Adaptador CarPlay para Citroën C4 Cactus 2014-2018 con Sistema SMEG/MRN',
            'brand' => 'PEUGEOT | CITROEN', 'model' => '1:208 | 2:C4 | 2:C3',
            'year_from' => 2014, 'year_to' => 2018, 'price_min' => 350,
        ]);
        ConfiguratorProduct::create([
            'handle' => 'camera-cactus', 'category' => 'camera', 'title' => 'Camera Citroen Cactus',
            'brand' => 'CITROEN', 'model' => 'C3 | C4 | Cactus', 'year_from' => 2006, 'year_to' => 2026,
        ]);
        $bot = app(WhatsAppTestBot::class);
        $r = $bot->conversationAnswer('Hola, tengo un Citroën C4 Cactus de 2014–2017 con sistema SMEG 5.4.B.R8, fecha 01-12-2015. Quiero CarPlay inalámbrico. ¿Qué referencia compatible y precio tiene?');
        $this->assertSame('offer', $r['mode']);
        $this->assertSame('C4 CACTUS', $r['context']['vehicle']['model']);
        $this->assertSame(2014, $r['context']['vehicle']['year']);
        $this->assertSame(1, $r['context']['matching_specific_screens']);
        $this->assertStringContainsString('/products/c4-cactus-smeg', $r['text']);
        $this->assertArrayNotHasKey('form', $r);
        $base = app(WhatsAppCatalogSearch::class)->search(['Citroen C4 2015']);
        $this->assertSame('C4', $base['vehicle']['model']);
        $this->assertSame(1, $base['matching_specific_screens']);
        Http::assertNothingSent();
    }

    public function test_followup_answers_known_points_then_emails_operator_without_repeating_offer(): void
    {
        ConfiguratorProduct::create([
            'handle' => 'audi-technical', 'category' => 'screen', 'title' => 'Radio Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009, 'price_min' => 300,
        ]);
        $output = fn ($answer, $insists) => ['status' => 'completed', 'output' => [[
            'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'answer' => $answer, 'unknown' => ['Necessità AUX'], 'needs_operator' => $insists,
            ])]],
        ]]];
        $this->aiPayloads = [
            $output('CarPlay wireless confermato. Quale auto hai?', false),
            $output('Il catalogo conferma CarPlay. Il requisito AUX richiede verifica.', true),
        ];
        Mail::shouldReceive('raw')->once()->withArgs(function ($body, $callback) {
            $this->assertStringContainsString('34611509809', $body);
            $this->assertStringContainsString('AUDI A4 2001', $body);
            $this->assertStringContainsString('audi-technical', $body);
            $this->assertStringContainsString('AUX', $body);
            $m = \Mockery::mock(Message::class);
            $m->shouldReceive('to')->once()->with('pollong@gmail.com')->andReturnSelf();
            $m->shouldReceive('subject')->once()->with('WhatsApp: verifica tecnica AUDI A4')->andReturnSelf();
            $callback($m);

            return true;
        })->andReturn(new \stdClass);
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->message(['id' => 'technical-offer', 'body' => 'Radio per Audi A4 2001']), time() - 1));
        $this->assertTrue($bot->reply($this->message(['id' => 'technical-first', 'body' => 'Serve AUX? Qual è il prezzo?']), time() - 1));
        $this->assertTrue($bot->reply($this->message(['id' => 'technical-insists', 'body' => 'Insisto: serve AUX?']), time() - 1));
        $this->assertFalse($bot->reply($this->message(['id' => 'after-alert', 'body' => 'AUX?']), time() - 1));
        $sent = Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/sendText'))->values();
        $this->assertStringContainsString('300 €', $sent[0][0]['text']);
        $this->assertStringContainsString('CarPlay wireless confermato.', $sent[1][0]['text']);
        $this->assertStringNotContainsString('?', $sent[1][0]['text']);
        $this->assertStringNotContainsString('/products/', $sent[1][0]['text']);
        $this->assertStringContainsString('Verrai ricontattato', $sent[2][0]['text']);
        $this->assertNotSame($sent[1][0]['text'], $sent[2][0]['text']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'openai.com') && $r['text']['format']['strict'] === true);
    }

    public function test_failed_operator_email_does_not_promise_contact_or_pause_chat(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP unavailable'));
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->message(['body' => 'Vorrei un operatore']), time() - 1));
        $sent = Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/sendText'))->last();
        $this->assertStringContainsString('non riesco a inoltrare', $sent[0]['text']);
        $this->assertStringNotContainsString('ricontattato', $sent[0]['text']);
        $this->assertFalse(Cache::store('file')->has('waha-test-handoff:'.app(WhatsAppAssistantSettings::class)->suffix()));
        $this->assertSame('failed', Cache::store('file')->get('waha-mail-status')['state']);
    }

    public function test_lexus_variants_spacing_and_brand_typos_are_recognized_without_ai(): void
    {
        app(WhatsAppAssistantSettings::class)->save(['fallback_language' => 'es']);
        ConfiguratorProduct::create(['handle' => 'lexus-rx-radio', 'category' => 'screen', 'title' => 'Lexus RX',
            'brand' => 'LEXUS', 'model' => 'RX', 'year_from' => 2010, 'year_to' => 2015, 'price_min' => 300]);
        $bot = app(WhatsAppTestBot::class);
        foreach (['Lexus rx450h aniversario del 2013', 'Lexus RX 450 H 2013', 'Lexsu RX-450h 2013'] as $message) {
            $r = $bot->answer($message);
            $this->assertSame('offer', $r['mode']);
            $this->assertSame(['brand' => 'LEXUS', 'model' => 'RX', 'year' => 2013], $r['context']['vehicle']);
            $this->assertStringStartsWith('Para LEXUS RX 2013:', $r['text']);
        }
        $r = app(WhatsAppCatalogSearch::class)->search(['Mitsubishi'], ['year' => 2013]);
        $this->assertSame(2013, $r['vehicle']['year']);
        Http::assertNothingSent();
    }

    public function test_unanswered_check_recovers_pending_questions_once_and_skips_manual_replies(): void
    {
        $messages = [
            $this->message(['id' => 'old-question', 'body' => 'Ciao', 'timestamp' => time() - 50]),
            ['id' => 'manual-reply', 'fromMe' => true, 'timestamp' => time() - 40, 'body' => 'Buongiorno'],
            $this->message(['id' => 'pending-one', 'body' => 'Spedite', 'timestamp' => time() - 30]),
            $this->message(['id' => 'pending-two', 'body' => 'anche a Tenerife?', 'timestamp' => time() - 20]),
        ];
        Http::fake(['*/api/default/chats/*/messages*' => function () use (&$messages) {
            return Http::response($messages);
        }]);
        $bot = app(WhatsAppTestBot::class);
        $r = $bot->recoverUnanswered();
        $this->assertSame(['state' => 'replied', 'questions' => 2, 'sent' => 1], $r);
        $this->assertSame('answered', $bot->recoverUnanswered()['state']);
        $sent = Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('spediamo ovunque', $sent->first()[0]['text']);
        $messages[] = ['id' => 'later-reply', 'fromMe' => true, 'timestamp' => time()];
        $this->assertSame(['state' => 'answered', 'questions' => 0, 'sent' => 0], $bot->recoverUnanswered());
    }

    public function test_universal_radios_use_native_catalog_filter_without_vehicle_coordinates(): void
    {
        foreach (['1DIN', '2DIN'] as $din) {
            $p = ConfiguratorProduct::create(['handle' => 'universal-'.strtolower($din), 'category' => 'screen', 'title' => 'Radio Universal 7” '.$din.' CarPlay',
                'model' => 'Universal', 'meta' => ['din' => $din], 'price_min' => 200]);
            $p->variants()->create(['title' => '8core 6G', 'option_value' => '8core 6G', 'price' => 300, 'shopify_variant_id' => '300']);
        }
        ConfiguratorProduct::create(['handle' => 'specific-audi', 'category' => 'screen', 'title' => 'Audi A1',
            'brand' => 'AUDI', 'model' => 'A1', 'year_from' => 2010, 'year_to' => 2014]);
        ConfiguratorProduct::create(['handle' => 'universal-dashcam', 'category' => 'screen', 'title' => 'Dashcam Universal CarPlay',
            'model' => 'Universal', 'meta' => ['din' => '2DIN', 'type' => 'DASHCAM'], 'price_min' => 50]);
        $bot = app(WhatsAppTestBot::class);
        foreach (['Avete autoradio universali 2DIN CarPlay?', '¿Tenéis radios universales 2DIN con CarPlay?', 'Do you have universal 2DIN CarPlay radios?'] as $text) {
            $r = $bot->answer($text, [], ['brand' => 'AUDI', 'model' => 'A1', 'year' => 2012]);
            $this->assertSame('offer', $r['mode']);
            $this->assertSame(1, $r['context']['matching_universal_screens']);
            $this->assertStringContainsString('/products/universal-2din', $r['text']);
            $this->assertStringNotContainsString('/products/specific-audi', $r['text']);
            $this->assertSame('universal', $r['quote_state']['catalog_mode']);
        }
        $r = $bot->answer('Autoradio universale 2DIN CarPlay');
        $this->assertSame('offer', $r['mode']);
        $this->assertSame([], $r['context']['vehicle']);
        $quote = $bot->conversationAnswer('Preventivo', [['role' => 'user', 'content' => 'Autoradio universale 2DIN CarPlay']], [], $r['signature'], false, [], '', [], $r['quote_state']);
        $this->assertSame('quote', $quote['mode']);
        $this->assertNotNull($quote['document']);
        Http::assertNothingSent();
    }

    public function test_universal_carplay_offer_uses_carplay_variant_instead_of_cheaper_no_carplay_version(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'universal-variant-test', 'category' => 'screen', 'title' => 'Radio MP5 Universal',
            'body_html' => 'Versioni No CarPlay e CarPlay.', 'model' => 'Universal', 'meta' => ['din' => '2DIN'], 'price_min' => 100]);
        $p->variants()->create(['title' => 'No CarPlay', 'option_value' => 'No CarPlay', 'price' => 100, 'shopify_variant_id' => '100']);
        $p->variants()->create(['title' => 'CarPlay', 'option_value' => 'CarPlay', 'price' => 200, 'shopify_variant_id' => '200']);
        $r = app(WhatsAppTestBot::class)->answer('Radio universale 2DIN con CarPlay');
        $this->assertSame('offer', $r['mode']);
        $this->assertStringContainsString('200 €', $r['text']);
        $this->assertStringContainsString('?variant=200', $r['text']);
        $this->assertStringNotContainsString('?variant=100', $r['text']);
        Http::assertNothingSent();
    }

    public function test_unanswered_general_question_is_answered_during_operator_handoff_without_clearing_it(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['standard_replies' => [['title' => 'Formato DIN', 'topic' => 'Formato DIN', 'category' => 'general', 'enabled' => true,
            'answers' => ['it' => '2DIN indica il formato del corpo dell’autoradio, non i pollici dello schermo.']]]]);
        Cache::store('file')->put('waha-test-handoff:'.$settings->suffix(), true, now()->addHour());
        Http::fake(['*/api/default/chats/*/messages*' => Http::response([$this->message(['body' => '2DIN cosa significa?'])])]);
        $r = app(WhatsAppTestBot::class)->recoverUnanswered();
        $this->assertSame('replied', $r['state']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], '2DIN indica'));
        $this->assertTrue(Cache::store('file')->has('waha-test-handoff:'.$settings->suffix()));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_unanswered_check_respects_chat_lock_and_uncertain_previous_send(): void
    {
        Http::fake(['*/api/default/chats/*/messages*' => Http::response([$this->message(['body' => 'Ciao'])])]);
        $bot = app(WhatsAppTestBot::class);
        $key = 'waha-test-message:'.hash('sha256', config('waha.session').$bot->chatId().'incoming-id');
        Cache::store('file')->put($key, 'send_attempt');
        $this->assertSame('needs_review', $bot->recoverUnanswered()['state']);
        Cache::store('file')->forget($key);
        $lock = Cache::store('file')->lock('waha-test-reply:'.hash('sha256', config('waha.session').$bot->chatId()), 120);
        $lock->get();
        try {
            $this->assertSame('busy', $bot->recoverUnanswered()['state']);
        } finally {
            $lock->release();
        }
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
    }

    public function test_new_radio_request_reopens_actual_chat_after_previous_missing_vehicle_handoff(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $suffix = $settings->suffix();
        Cache::store('file')->put('waha-test-handoff:'.$suffix, true, now()->addHour());
        Cache::store('file')->put('waha-test-vehicle:'.$suffix, ['brand' => 'FIAT', 'model' => '500', 'year' => 2021]);
        Cache::store('file')->put('waha-test-form:'.$suffix, ['data' => ['brand' => 'FIAT', 'model' => '500', 'year' => 2021]]);
        ConfiguratorProduct::create(['handle' => 'new-radio-audi', 'category' => 'screen', 'title' => 'Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2010, 'price_min' => 300]);
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->message(['body' => 'Otra radio para Audi A4 2008']), time() - 1));
        $this->assertFalse(Cache::store('file')->has('waha-test-handoff:'.$suffix));
        $this->assertSame('AUDI', Cache::store('file')->get('waha-test-vehicle:'.$suffix)['brand']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], '/products/new-radio-audi'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_additional_authorized_number_has_separate_chat_memory_and_handoff(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['additional_test_numbers' => ['393758151418']]);
        Cache::store('file')->put('waha-test-handoff:'.$settings->suffix(), true, now()->addHour());
        Http::fake(['*/api/default/lids/pn/393758151418' => Http::response(['pn' => '393758151418@c.us', 'lid' => '777777777@lid'])]);
        $bot = new WhatsAppTestBot;
        $bot->useNumber('393758151418');
        $bot->connect();
        $this->assertSame('393758151418@c.us', $bot->chatId());
        $this->assertFalse($bot->accepts($this->message(), time() - 1));
        $message = $this->message(['id' => 'second-number', 'from' => '777777777@lid', 'body' => 'Ciao']);
        $this->assertTrue($bot->reply($message, time() - 1));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && $r['chatId'] === '393758151418@c.us');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && $r['chatId'] === '34611509809@c.us');
        $this->assertTrue(Cache::store('file')->has('waha-test-handoff:'.$settings->suffix()));
        $settings->save(['additional_test_numbers' => []]);
        $this->expectException(RuntimeException::class);
        $bot->chatId();
    }

    public function test_service_followups_do_not_change_known_vehicle_or_replay_obsolete_history(): void
    {
        foreach (['Up', 'Polo'] as $model) {
            ConfiguratorProduct::create(['handle' => strtolower($model), 'category' => 'screen', 'title' => 'VW '.$model,
                'brand' => 'VOLKSWAGEN', 'model' => $model, 'year_from' => 2010, 'year_to' => 2023]);
        }
        $vehicle = ['brand' => 'VOLKSWAGEN', 'model' => 'Up', 'year' => 2017];
        $search = app(WhatsAppCatalogSearch::class);
        $r = $search->search(['Volkswagen Polo 2010', 'Volkswagen Up 2017', 'Muchas gracias. El servicio de instalación se les podría encargar a ustedes o no lo ofrecen?'], $vehicle);
        $this->assertSame($vehicle, $r['vehicle']);
        $r = $search->search(['Buenos días. Estoy en Las Palmas de Gran Canaria'], $vehicle);
        $this->assertSame($vehicle, $r['vehicle']);
        $r = $search->search(['Ahora es un Volkswagen Polo 2015'], $vehicle);
        $this->assertSame(['brand' => 'VOLKSWAGEN', 'model' => 'Polo', 'year' => 2015], $r['vehicle']);
        Http::assertNothingSent();
    }

    public function test_catalog_model_families_spacing_and_engine_variants_work_across_brands(): void
    {
        $fixtures = [
            ['AUDI', 'A4 B6', 2000, 2004], ['AUDI', 'A4 B8', 2008, 2015],
            ['BMW', 'Serie 3 E46', 1998, 2005], ['BMW', 'Serie 3 E90', 2006, 2012],
            ['TOYOTA', 'RAV4 XA30', 2006, 2012], ['NISSAN', 'Qashqai J10', 2007, 2013],
            ['LEXUS', 'UX', 2018, 2025], ['VOLKSWAGEN', 'Golf V', 2003, 2008],
        ];
        foreach ($fixtures as [$brand, $model, $from, $to]) {
            ConfiguratorProduct::create(['handle' => strtolower(str_replace(' ', '-', $brand.$model)), 'category' => 'screen',
                'title' => $brand.' '.$model, 'brand' => $brand, 'model' => $model,
                'year_from' => $from, 'year_to' => $to, 'price_min' => 300]);
        }
        foreach ([
            'Audi A 4 2.0 TDI 2010' => ['AUDI', 'A4 B8'],
            'BMW 320d 2009' => ['BMW', 'Serie 3 E90'],
            'Toyota Rav 4 2009' => ['TOYOTA', 'RAV4 XA30'],
            'Nissan Qasqhai 1.5 dci 2011' => ['NISSAN', 'Qashqai J10'],
            'Lexus UX250h 2020' => ['LEXUS', 'UX'],
            'VW Golf 1.9 tdi 2005' => ['VOLKSWAGEN', 'Golf V'],
            'Qashqai 2011' => ['NISSAN', 'Qashqai J10'],
            'A 4 2010' => ['AUDI', 'A4 B8'],
        ] as $message => [$brand, $model]) {
            $r = app(WhatsAppTestBot::class)->answer($message);
            $this->assertSame('offer', $r['mode'], $message);
            $this->assertSame($brand, $r['context']['vehicle']['brand'], $message);
            $this->assertSame($model, $r['context']['vehicle']['model'], $message);
        }
        Http::assertNothingSent();
    }

    public function test_generic_family_requires_confirmation_when_catalog_generations_overlap(): void
    {
        foreach (['A4 B7', 'A4 B8'] as $model) {
            ConfiguratorProduct::create(['handle' => str_replace(' ', '-', strtolower($model)), 'category' => 'screen',
                'title' => 'Audi '.$model, 'brand' => 'AUDI', 'model' => $model, 'year_from' => 2007, 'year_to' => 2009]);
        }
        $r = app(WhatsAppTestBot::class)->answer('Audi A 4 2008');
        $this->assertSame('vehicle_details', $r['mode']);
        $this->assertSame(['A4 B7', 'A4 B8'], $r['context']['vehicle']['_model_candidates']);
        Http::assertNothingSent();
    }

    public function test_mercedes_class_and_engine_badge_resolve_catalog_model_using_year_without_ai(): void
    {
        foreach (['Clase A W168' => [1998, 2004], 'Clase A W169' => [2004, 2012]] as $model => [$from, $to]) {
            ConfiguratorProduct::create(['handle' => str_replace(' ', '-', strtolower($model)), 'category' => 'screen',
                'title' => 'Mercedes '.$model, 'brand' => 'MERCEDES', 'model' => $model,
                'year_from' => $from, 'year_to' => $to, 'price_min' => 300]);
        }
        $bot = app(WhatsAppTestBot::class);
        foreach (['Mercedes Benz clase a 140 2001', 'Mercedes Classe A 140 del 2001', 'Mercedes A Class A140 2001', 'Mercedes A140 2001'] as $text) {
            $r = $bot->answer($text);
            $this->assertSame('offer', $r['mode']);
            $this->assertSame('Clase A W168', $r['context']['vehicle']['model']);
            $this->assertSame(2001, $r['context']['vehicle']['year']);
            $this->assertSame(1, $r['context']['matching_specific_screens']);
        }
        $r = $bot->answer('Mercedes clase A 140 2004');
        $this->assertSame('vehicle_details', $r['mode']);
        $this->assertSame(['Clase A W168', 'Clase A W169'], $r['context']['vehicle']['_model_candidates']);
        Http::assertNothingSent();
    }

    public function test_brand_is_inferred_from_unique_model_preferring_the_full_model_without_ai(): void
    {
        foreach (['Golf', 'Golf Plus'] as $model) {
            ConfiguratorProduct::create(['handle' => str_replace(' ', '-', strtolower($model)), 'category' => 'screen',
                'title' => 'Volkswagen '.$model, 'brand' => 'VOLKSWAGEN', 'model' => $model,
                'year_from' => 2006, 'year_to' => 2015, 'price_min' => 300]);
        }
        $r = app(WhatsAppTestBot::class)->answer('Preguntaba si tenían alguna pantalla de radio para un golf plus del 2011');
        $this->assertSame('offer', $r['mode']);
        $this->assertSame(['model' => 'Golf Plus', 'brand' => 'VOLKSWAGEN', 'year' => 2011], $r['context']['vehicle']);
        $this->assertSame(1, $r['context']['matching_specific_screens']);
        $r = app(WhatsAppCatalogSearch::class)->search(['Golf Plus'], ['year' => 2011]);
        $this->assertSame('VOLKSWAGEN', $r['vehicle']['brand']);
        $this->assertSame(2011, $r['vehicle']['year']);
        Http::assertNothingSent();
    }

    public function test_shared_model_name_does_not_infer_an_arbitrary_brand(): void
    {
        foreach (['FORD', 'TOYOTA'] as $brand) {
            ConfiguratorProduct::create(['handle' => strtolower($brand).'-shared', 'category' => 'screen',
                'title' => $brand.' Shared', 'brand' => $brand, 'model' => 'Shared', 'year_from' => 2010, 'year_to' => 2020]);
        }
        $r = app(WhatsAppTestBot::class)->answer('Shared 2011');
        $this->assertSame('vehicle_details', $r['mode']);
        $this->assertSame('Shared', $r['context']['vehicle']['model']);
        $this->assertArrayNotHasKey('brand', $r['context']['vehicle']);
        Http::assertNothingSent();
    }

    public function test_named_accessory_bypasses_vehicle_questions_and_unrelated_screens(): void
    {
        $title = 'Cámara Trasera Universal AHD/CVBS Gran Angular 170° Ojo de Pez · Visión Nocturna HD';
        $p = ConfiguratorProduct::create(['handle' => 'camera-ahd', 'category' => 'camera',
            'title' => $title.' · Aparcamiento Seguro Impermeable', 'price_min' => 39]);
        $p->variants()->create(['title' => 'AHD', 'price' => 39, 'shopify_variant_id' => '987']);
        ConfiguratorProduct::create(['handle' => 'unrelated-radio', 'category' => 'screen', 'title' => 'Radio Android', 'brand' => 'FIAT', 'model' => '500']);
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        $r = app(WhatsAppTestBot::class)->answer('hola buenos dias, estoy interesado en comprar "'.$title.'"');
        $this->assertSame('accessory_offer', $r['mode']);
        $this->assertStringContainsString('39,00 €', $r['text']);
        $this->assertStringContainsString('/products/camera-ahd?variant=987', $r['text']);
        $this->assertSame([], $r['context']['vehicle']);
        $this->assertSame(0, $r['context']['matching_specific_screens']);
        Http::assertNothingSent();
    }

    public function test_ambiguous_accessory_titles_do_not_select_an_arbitrary_product(): void
    {
        foreach (['A', 'B'] as $suffix) {
            ConfiguratorProduct::create(['handle' => 'camera-'.$suffix, 'category' => 'camera',
                'title' => 'Universal rear camera night vision wide angle waterproof '.$suffix]);
        }
        $service = app(WhatsAppProductOffer::class);
        $this->assertNull($service->namedAccessory('Universal rear camera night vision wide angle waterproof', 'en', []));
        Http::assertNothingSent();
    }

    public function test_screen_recommendation_is_local_and_eight_core_has_priority_over_ram(): void
    {
        ConfiguratorProduct::create(['handle' => 'peugeot-advice', 'category' => 'screen', 'title' => 'Peugeot 206',
            'brand' => 'PEUGEOT', 'model' => '206', 'year_from' => 1998, 'year_to' => 2010, 'price_min' => 300]);
        $vehicle = ['brand' => 'PEUGEOT', 'model' => '206', 'year' => 2001];
        $r = app(WhatsAppTestBot::class)->answer('perfetto quale mi consigliate?', [], $vehicle, hash('sha256', json_encode($vehicle)));
        $this->assertSame('standard', $r['mode']);
        $this->assertStringContainsString('Guarda le foto', $r['text']);
        $this->assertStringContainsString('preventivo', $r['text']);
        $variants = collect([
            ['option' => '4core 2G 32G', 'price' => 100],
            ['option' => '4core 4G 64G', 'price' => 200],
            ['option' => '8core 6G 128G', 'price' => 300],
            ['option' => '8core 8G 256G', 'price' => 400],
        ]);
        $offer = app(WhatsAppProductOffer::class);
        $this->assertSame('8core 6G 128G', $offer->preferredVariants($variants)->sortBy('price')->first()['option']);
        $this->assertSame('4core 4G 64G', $offer->preferredVariants($variants->take(2))->first()['option']);
        $this->assertTrue($offer->preferredVariants($variants->take(1))->isEmpty());
        Http::assertNothingSent();
    }

    public function test_misspelled_brand_preserves_explicit_numeric_model_even_when_not_in_catalog(): void
    {
        ConfiguratorProduct::create(['handle' => 'peugeot-206', 'category' => 'screen', 'title' => 'Peugeot 206',
            'brand' => 'PEUGEOT', 'model' => '206', 'year_from' => 1998, 'year_to' => 2010, 'price_min' => 300]);
        $search = app(WhatsAppCatalogSearch::class);
        $r = $search->search(['ciao ho una peugeor 205 annoi 2001 che radio averte?']);
        $this->assertSame(['brand' => 'PEUGEOT', 'model' => '205', 'year' => 2001], $r['vehicle']);
        $this->assertSame(0, $r['matching_specific_screens']);
        $r = $search->search(['ciao ho una peugeor 206 annoi 2001']);
        $this->assertSame(['brand' => 'PEUGEOT', 'model' => '206', 'year' => 2001], $r['vehicle']);
        $this->assertSame(1, $r['matching_specific_screens']);
        $r = $search->search(['Peugeor anno 2001']);
        $this->assertArrayNotHasKey('model', $r['vehicle']);
        $this->assertSame(2001, $r['vehicle']['year']);
        Http::assertNothingSent();
    }

    public function test_ambiguous_model_asks_confirmation_and_unclear_answer_requests_only_missing_coordinate(): void
    {
        app(WhatsAppAssistantSettings::class)->save(['fallback_language' => 'es']);
        foreach (['Auris', 'Yaris'] as $model) {
            ConfiguratorProduct::create(['handle' => strtolower($model), 'category' => 'screen', 'title' => 'Toyota '.$model,
                'brand' => 'TOYOTA', 'model' => $model, 'year_from' => 2010, 'year_to' => 2020, 'price_min' => 300]);
        }
        $bot = app(WhatsAppTestBot::class);
        $r = $bot->answer('Toyota Aris 2013');
        $this->assertSame('vehicle_details', $r['mode']);
        $this->assertStringContainsString('Auris', $r['text']);
        $this->assertStringContainsString('Yaris', $r['text']);
        $r = $bot->answer('no entiendo', [['role' => 'assistant', 'content' => '¿Cuál es el modelo de tu coche?']], ['brand' => 'TOYOTA', 'year' => 2013]);
        $this->assertSame('Escríbeme solo el modelo de tu coche.', $r['text']);
        $this->assertSame(2013, $r['context']['vehicle']['year']);
        Http::assertNothingSent();
    }

    public function test_quote_is_sent_as_pdf_document_to_authorized_contact_without_ai_or_text_duplicate(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'pdf-radio', 'category' => 'screen', 'title' => 'Radio 7” Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009, 'price_min' => 300]);
        $p->variants()->create(['title' => 'Base', 'option_value' => 'Base', 'price' => 300, 'shopify_variant_id' => '123']);
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $message = $this->message(['body' => 'Preventivo Audi A4 2001']);
        $this->assertTrue($bot->reply($message, time() - 1));
        $this->assertFalse($bot->reply($message, time() - 1));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendFile') && $r['chatId'] === '34611509809@c.us'
            && $r['file']['mimetype'] === 'application/pdf' && str_ends_with($r['file']['filename'], '.pdf')
            && str_starts_with(base64_decode($r['file']['data']), '%PDF-'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') || str_contains($r->url(), 'openai.com'));
    }
}
