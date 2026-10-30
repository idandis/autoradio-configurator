<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\InstallationZone;
use App\Models\User;
use App\Services\WhatsAppAssistantSettings;
use App\Services\WhatsAppCatalogSearch;
use App\Services\WhatsAppQuotePdf;
use App\Services\WhatsAppStandardReplies;
use App\Services\WhatsAppTestBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WhatsAppAssistantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partialMock(WhatsAppQuotePdf::class)->shouldReceive('render')->andReturnUsing(fn ($html) => "%PDF-1.4\n".$html."\n%%EOF");
        config(['mail.mailers.smtp.transport' => 'array']);
        Sleep::fake();
        Storage::fake('local');
        $this->withSession(['whatsapp_preview' => ['last_activity' => now()->timestamp]]);
        $this->cacheDir = sys_get_temp_dir().'/autoradio-settings-test-'.bin2hex(random_bytes(6));
        config(['cache.stores.file.path' => $this->cacheDir]);
        config(['waha.test_enabled' => true, 'waha.test_number' => '34611509809']);
        app(WhatsAppAssistantSettings::class)->save(['fallback_language' => 'it']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cacheDir);
        parent::tearDown();
    }

    private function introducedVehicle(): void
    {
        $vehicle = ['brand' => 'AUDI', 'model' => 'A4', 'year' => 2001];
        $this->withSession(['whatsapp_preview' => ['last_activity' => now()->timestamp,
            'vehicle' => $vehicle, 'offer' => hash('sha256', json_encode($vehicle))]]);
    }

    public function test_only_administrators_can_manage_rules_preview_and_reset(): void
    {
        $this->get('/dashboard/whatsapp')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get('/dashboard/whatsapp')->assertForbidden();
        $this->put('/dashboard/whatsapp', [])->assertForbidden();
        $this->post('/dashboard/whatsapp/preview', ['message' => 'Ciao'])->assertForbidden();
        $this->post('/dashboard/whatsapp/reset')->assertForbidden();
        $this->post('/dashboard/whatsapp/preview/reset')->assertForbidden();
        $this->get('/dashboard/whatsapp/status')->assertForbidden();
    }

    public function test_saved_rules_are_persistent_validated_and_do_not_expose_credentials(): void
    {
        config(['waha.key' => 'PRIVATE-WAHA-KEY', 'services.openai.api_key' => 'PRIVATE-AI-KEY']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rules = app(WhatsAppAssistantSettings::class)->get();
        $rules = array_replace($rules, ['test_number' => '+34 611 509 809', 'max_products' => 1, 'max_words' => 20, 'show_links' => false]);
        $this->put('/dashboard/whatsapp', $rules)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/dashboard/whatsapp')->assertOk()->assertDontSee('PRIVATE-WAHA-KEY')->assertDontSee('PRIVATE-AI-KEY')
            ->assertInertia(fn (Assert $p) => $p->component('WhatsAppAssistant')
                ->where('rules.test_number', '34611509809')->where('rules.max_products', 1)
                ->where('rules.max_words', 20)->where('rules.show_links', false));
        $this->put('/dashboard/whatsapp', array_replace($rules, ['test_number' => 'abc', 'max_products' => 99, 'max_words' => 500]))
            ->assertSessionHasErrors(['test_number', 'max_products', 'max_words']);
        $this->assertSame(1, app(WhatsAppAssistantSettings::class)->get()['max_products']);
    }

    public function test_preview_obeys_saved_offer_rules_without_network_or_conversation_changes(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([7, 9] as $size) {
            ConfiguratorProduct::create([
                'handle' => 'audi-a4-'.$size, 'category' => 'screen', 'title' => 'Pantalla '.$size.'" Audi A4',
                'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009, 'price_min' => 300 + $size,
            ]);
        }
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['max_products' => 1, 'show_prices' => false, 'show_links' => false, 'language' => 'en']);
        $key = 'waha-test-history:'.$settings->suffix();
        Cache::store('file')->put($key, [['role' => 'user', 'content' => 'Existing history']], now()->addMinute());
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Ciao Audi A4 2001']);
        $r->assertOk()->assertJsonPath('mode', 'offer')->assertJsonPath('matches', 2);
        $text = $r->json('text');
        $this->assertStringContainsString('For AUDI A4 2001', $text);
        $this->assertSame(1, substr_count($text, '•'));
        $this->assertStringNotContainsString('€', $text);
        $this->assertStringContainsString('https://', $text);
        $this->assertSame('Existing history', Cache::store('file')->get($key)[0]['content']);
        Http::assertNothingSent();
        Cache::store('file')->forget($key);
    }

    public function test_reset_clears_only_test_chat_memory_and_handoff_without_duplicate_markers(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $settings = app(WhatsAppAssistantSettings::class);
        foreach (['history', 'vehicle', 'offer', 'handoff'] as $kind) {
            Cache::store('file')->put('waha-test-'.$kind.':'.$settings->suffix(), true, now()->addMinute());
        }
        Cache::store('file')->put('waha-test-message:sent-test', true, now()->addMinute());
        $this->postJson('/dashboard/whatsapp/reset')->assertOk();
        foreach (['history', 'vehicle', 'offer', 'handoff'] as $kind) {
            $this->assertFalse(Cache::store('file')->has('waha-test-'.$kind.':'.$settings->suffix()));
        }
        $this->assertTrue(Cache::store('file')->has('waha-test-message:sent-test'));
        Cache::store('file')->forget('waha-test-message:sent-test');
        Cache::store('file')->forget('waha-test-revision:'.$settings->suffix());
    }

    public function test_ai_preview_applies_word_question_language_and_link_rules(): void
    {
        ConfiguratorProduct::create(['handle' => 'ai-radio', 'category' => 'screen', 'title' => 'Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009, 'price_min' => 300]);
        $this->introducedVehicle();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save([
            'direct_offers' => false, 'max_words' => 15, 'max_questions' => 0,
            'language' => 'es', 'tone' => 'cordiale', 'show_links' => false, 'custom_rules' => 'Non ripetere saluti.',
        ]);
        config(['services.openai.api_key' => 'test-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'status' => 'completed', 'output' => [['type' => 'message', 'content' => [
                ['type' => 'output_text', 'text' => json_encode(['answer' => 'Risposta tecnica valida. Quale radio? Quale auto? '.str_repeat('dettaglio ', 40).'https://example.com', 'unknown' => [], 'needs_operator' => false])],
            ]]],
        ])]);
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Una informazione tecnica']);
        $r->assertOk()->assertJsonPath('mode', 'ai');
        $text = $r->json('text');
        $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', $text)));
        $this->assertStringNotContainsString('?', $text);
        $this->assertStringNotContainsString('https://', $text);
        Http::assertSent(fn ($r) => str_contains($r['instructions'], 'Lingua: es')
            && str_contains($r['instructions'], 'cordiale') && str_contains($r['instructions'], 'Non ripetere saluti.'));
        Http::assertSentCount(1);
    }

    public function test_handoff_preview_does_not_pause_the_actual_chat(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $settings = app(WhatsAppAssistantSettings::class);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Vorrei un operatore'])
            ->assertOk()->assertJsonPath('mode', 'handoff');
        $this->assertFalse(Cache::store('file')->has('waha-test-handoff:'.$settings->suffix()));
        Http::assertNothingSent();
    }

    public function test_standard_replies_are_saved_and_answer_without_catalog_or_ai(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rules = app(WhatsAppAssistantSettings::class)->get();
        $rules['standard_replies'] = [
            ['answer' => 'Il CarPlay è incluso.', 'enabled' => true],
            ['answer' => 'Il 4G è disponibile solo da 8 core e 6 GB di RAM in su.', 'enabled' => true],
        ];
        $this->put('/dashboard/whatsapp', $rules)->assertSessionHasNoErrors();
        $this->introducedVehicle();
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Ha CarPlay e 4G?'])
            ->assertOk()->assertJsonPath('mode', 'standard')
            ->assertJsonPath('text', "Il CarPlay è incluso.\n\nIl 4G è disponibile solo da 8 core e 6 GB di RAM in su.");
        Http::assertNothingSent();
    }

    public function test_standard_reply_matching_rejects_disabled_ambiguous_and_model_requests(): void
    {
        $service = app(WhatsAppStandardReplies::class);
        $replies = [['answer' => 'Ha GPS.', 'enabled' => true]];
        $this->assertSame('Ha GPS.', $service->match('Ha il navigatore?', $replies));
        foreach (['GPS prezzo?', 'GPS per modello Audi?', 'Ha GPS e CarPlay?', 'GPS 2001?'] as $question) {
            $this->assertNull($service->match($question, $replies));
        }
        $this->assertNull($service->match('Ha GPS?', [['answer' => 'Ha GPS.', 'enabled' => false]]));
        $this->assertNull($service->match('Ha GPS?', [...$replies, ...$replies]));
    }

    public function test_standard_replies_validate_answer_and_limit(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rules = app(WhatsAppAssistantSettings::class)->get();
        $rules['standard_replies'] = [['answer' => '', 'enabled' => true]];
        $this->put('/dashboard/whatsapp', $rules)->assertSessionHasErrors('standard_replies.0.answer');
        $rules['standard_replies'] = array_fill(0, 51, ['answer' => 'Ha GPS.', 'enabled' => true]);
        $this->put('/dashboard/whatsapp', $rules)->assertSessionHasErrors('standard_replies');
    }

    public function test_translated_standard_reply_uses_customer_language_without_ai(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rules = app(WhatsAppAssistantSettings::class)->get();
        $rules['standard_replies'] = [['enabled' => true, 'answers' => [
            'it' => 'Il CarPlay è incluso.', 'es' => 'CarPlay está incluido.', 'en' => 'CarPlay is included.',
        ]]];
        $this->put('/dashboard/whatsapp', $rules)->assertSessionHasNoErrors();
        $this->introducedVehicle();
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        foreach (['Ha CarPlay?' => 'Il CarPlay è incluso.', '¿Tiene CarPlay?' => 'CarPlay está incluido.', 'Does it have CarPlay?' => 'CarPlay is included.'] as $message => $answer) {
            $this->introducedVehicle();
            $this->postJson('/dashboard/whatsapp/preview', ['message' => $message])
                ->assertOk()->assertJsonPath('mode', 'standard')->assertJsonPath('text', $answer);
        }
        app(WhatsAppAssistantSettings::class)->save(['language' => 'en']);
        $this->introducedVehicle();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Ha CarPlay?'])
            ->assertOk()->assertJsonPath('text', 'CarPlay is included.');
        Http::assertNothingSent();
    }

    public function test_missing_translation_falls_back_and_legacy_answers_remain_italian(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['standard_replies' => [['answer' => 'Ha GPS.', 'enabled' => true]]]);
        $reply = $settings->get()['standard_replies'][0];
        $this->assertSame(['it' => 'Ha GPS.', 'es' => '', 'en' => ''], $reply['answers']);
        $service = app(WhatsAppStandardReplies::class);
        $this->assertSame('Ha GPS.', $service->match('GPS?', [$reply], 'it'));
        $this->assertNull($service->match('GPS?', [$reply], 'es'));
        $reply['answers']['es'] = null;
        $this->assertNull($service->match('GPS?', [$reply], 'es'));
    }

    public function test_explicit_topics_keep_voice_reply_separate_from_carplay_and_gps(): void
    {
        $service = app(WhatsAppStandardReplies::class);
        $replies = [
            ['topic' => 'CarPlay', 'enabled' => true, 'answers' => ['it' => 'CarPlay wireless incluso.']],
            ['topic' => 'GPS', 'enabled' => true, 'answers' => ['it' => 'Navigazione GPS inclusa.']],
            ['topic' => 'Comandi vocali', 'enabled' => true, 'answers' => ['it' => 'Con CarPlay o Android Auto puoi usare l’assistente vocale per musica e navigazione.']],
        ];
        $this->assertSame('CarPlay wireless incluso.', $service->match('Ha CarPlay?', $replies));
        $this->assertSame('Navigazione GPS inclusa.', $service->match('Ha GPS?', $replies));
        $this->assertSame($replies[2]['answers']['it'], $service->match('Ha comandi vocali?', $replies));
        foreach ([
            'Radio FM' => ['Radio FM?', 'FM radio?'], 'USB' => ['Ha USB?', 'USB?'],
            'Equalizzatore' => ['Ha equalizzatore?', 'Tiene ecualizador?', 'Has equalizer?'],
            'Lingue' => ['Quali lingue?', 'Qué idiomas?', 'Which languages?'],
            'Schermo touch' => ['Schermo touch?', 'Pantalla táctil?', 'Has touchscreen?'],
            'Installazione' => ['Installatore?', 'Instalador?', 'Local installer?'],
            'Comandi vocali' => ['Comandi vocali?', 'Asistente de voz?', 'Voice commands?'],
        ] as $topic => $questions) {
            foreach ($questions as $question) {
                $this->assertContains($topic, $service->topics($question));
            }
        }
    }

    public function test_original_functions_question_uses_saved_reply_without_catalog_or_ai(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $answer = 'Sì, le principali funzioni originali vengono mantenute. A seconda dell’allestimento dell’auto, alcune potrebbero non essere più disponibili, ma solitamente si tratta di funzioni secondarie o sostituite dalle funzionalità della nuova autoradio.';
        app(WhatsAppAssistantSettings::class)->save(['standard_replies' => [[
            'title' => 'FUNZIONI ORIGINALI AUTO', 'enabled' => true,
            'answers' => ['it' => $answer, 'es' => 'Sí, se mantienen las principales funciones originales.', 'en' => 'Yes, the main factory functions are retained.'],
        ]]]);
        $this->introducedVehicle();
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'QUESTA RADIO MANTIENE LE FUNZIONI ORIGINALI DELLA MIA AUTO?'])
            ->assertOk()->assertJsonPath('mode', 'standard')->assertJsonPath('text', $answer);
        $service = app(WhatsAppStandardReplies::class);
        foreach (['Conserva le funzionalità originali?', '¿Mantiene las funciones originales?', 'Does it keep factory features?'] as $question) {
            $this->assertContains('Funzioni originali', $service->topics($question));
        }
        $this->assertSame('Testo personalizzato.', $service->match('Funzioni originali?', [[
            'title' => 'Funzioni originali', 'enabled' => true, 'answers' => ['it' => 'Testo personalizzato.'],
        ]]));
        Http::assertNothingSent();
    }

    public function test_original_car_function_question_selects_english_standard_reply(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $answers = ['it' => 'Le funzioni originali vengono mantenute.', 'es' => 'Se mantienen las funciones originales.', 'en' => 'The original car functions are retained.'];
        app(WhatsAppAssistantSettings::class)->save(['language' => 'auto', 'standard_replies' => [[
            'title' => 'FUNZIONI ORIGINALI AUTO', 'enabled' => true, 'answers' => $answers,
        ]]]);
        $this->introducedVehicle();
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        foreach ([
            'CAN RETAIN ORIGINAL CAR FUNCTION?' => 'en',
            'Will it keep the original vehicle functions?' => 'en',
            'Can it retain factory car features?' => 'en',
            'Does it retain OEM functions?' => 'en',
            '¿Mantiene las funciones originales del coche?' => 'es',
            'QUESTA RADIO MANTIENE LE FUNZIONI ORIGINALI DELLA MIA AUTO?' => 'it',
        ] as $message => $locale) {
            $this->introducedVehicle();
            $this->postJson('/dashboard/whatsapp/preview', ['message' => $message])
                ->assertOk()->assertJsonPath('mode', 'standard')->assertJsonPath('text', $answers[$locale]);
        }
        $settings = app(WhatsAppAssistantSettings::class);
        $this->assertSame('en', $settings->locale(['Ciao', 'CAN RETAIN ORIGINAL CAR FUNCTION?']));
        $this->assertSame('it', $settings->locale(['Can it retain original car functions?', 'Mantiene le funzioni originali?']));
        Http::assertNothingSent();
    }

    public function test_custom_questions_work_for_new_topics_in_all_languages(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $settings = app(WhatsAppAssistantSettings::class);
        $rules = $settings->get();
        $rules['standard_replies'] = [[
            'title' => 'Pagamento rateale', 'enabled' => true, 'category' => 'product',
            'answers' => ['it' => 'Risposta italiana.', 'es' => 'Respuesta española.', 'en' => 'English answer.'],
            'questions' => ['it' => 'Posso pagare in tre rate?', 'es' => '¿Se puede pagar a plazos?', 'en' => 'Can I pay in instalments?'],
        ]];
        $this->put('/dashboard/whatsapp', $rules)->assertSessionHasNoErrors();
        $this->introducedVehicle();
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        foreach (['POSSO PAGARE IN TRE RATE!!!' => 'Risposta italiana.', 'Se puede pagar a plazos?' => 'Respuesta española.', 'CAN I PAY IN INSTALMENTS?' => 'English answer.', 'Pagamento rateale?' => 'Risposta italiana.'] as $question => $answer) {
            $this->introducedVehicle();
            $this->postJson('/dashboard/whatsapp/preview', ['message' => $question])
                ->assertOk()->assertJsonPath('mode', 'standard')->assertJsonPath('text', $answer);
        }
        $service = app(WhatsAppStandardReplies::class);
        $this->assertNull($service->match('Pagamento rateale?', [...$rules['standard_replies'], ...$rules['standard_replies']]));
        Http::assertNothingSent();
    }

    public function test_preview_keeps_context_and_reset_does_not_change_real_chat(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $settings = app(WhatsAppAssistantSettings::class);
        $key = 'waha-test-history:'.$settings->suffix();
        Cache::store('file')->put($key, [['role' => 'user', 'content' => 'Chat reale']], now()->addHour());
        $bot = $this->partialMock(WhatsAppTestBot::class);
        $context = ['vehicle' => ['brand' => 'AUDI', 'model' => 'A4', 'year' => 2001], 'matching_specific_screens' => 1];
        $bot->shouldReceive('answer')->once()->with('Audi A4 2001', [], [], null)
            ->andReturn(['text' => 'Proposta Audi.', 'mode' => 'offer', 'context' => $context, 'signature' => 'offer-audi']);
        $bot->shouldReceive('answer')->once()->with('Ha GPS?', [
            ['role' => 'user', 'content' => 'Audi A4 2001'], ['role' => 'assistant', 'content' => 'Proposta Audi.'],
        ], $context['vehicle'], 'offer-audi')
            ->andReturn(['text' => 'Sì, ha GPS.', 'mode' => 'standard', 'context' => $context, 'signature' => 'offer-audi']);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Audi A4 2001'])
            ->assertOk()->assertJsonCount(1, 'chat');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Ha GPS?'])
            ->assertOk()->assertJsonCount(2, 'chat')->assertJsonPath('chat.0.question', 'Audi A4 2001');
        $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk()->assertSessionMissing('whatsapp_preview');
        $this->assertSame('Chat reale', Cache::store('file')->get($key)[0]['content']);
        Http::assertNothingSent();
    }

    public function test_preview_simulates_welcome_delay_inactivity_and_reset_without_whatsapp(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['reply_delay_seconds' => 4, 'welcome_messages' => [
            'it' => 'Benvenuto personalizzato.', 'es' => 'Bienvenida personalizada.', 'en' => 'Custom welcome.',
        ]]);
        $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Hola!'])
            ->assertOk()->assertJsonPath('mode', 'greeting')->assertJsonPath('text', app(\App\Services\WhatsAppWelcome::class)->initialMessage('es'));
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Hola!'])
            ->assertOk()->assertJsonPath('text', '¿De qué marca es tu coche?');
        $this->travel(23)->hours();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Hola!'])
            ->assertOk()->assertJsonPath('text', '¿De qué marca es tu coche?');
        $this->travel(24)->hours();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Good evening!'])
            ->assertOk()->assertJsonPath('text', app(\App\Services\WhatsAppWelcome::class)->initialMessage('en'));
        $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Buonasera!'])
            ->assertOk()->assertJsonCount(1, 'chat')->assertJsonPath('text', app(\App\Services\WhatsAppWelcome::class)->initialMessage('it'));
        Sleep::assertSleptTimes(5);
        Http::assertNothingSent();
        $this->travelBack();
    }

    public function test_spanish_morning_greeting_without_accent_never_hands_off_in_a_new_chat(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'auto', 'fallback_language' => 'it']);
        Mail::fake();
        foreach (['Buenos dias', 'buenos días', 'BUENOS DIAS!', '¡Buenos dias!', '  Buenos   dias  '] as $text) {
            $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
            $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => $text])
                ->assertOk()->assertJsonPath('mode', 'greeting');
            $this->assertSame(app(\App\Services\WhatsAppWelcome::class)->initialMessage('es'), $r->json('text'));
            $this->assertStringNotContainsString('¿De qué marca es tu coche?', $r->json('text'));
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_initial_greeting_requests_province_vehicle_and_radio_photo_in_each_language(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'auto']);
        Mail::fake();
        foreach ([
            'Ciao' => ['Ciao 👋', 'provincia', 'marca, il modello e l’anno', 'foto del vano'],
            'Buenos dias' => ['Hola 👋', 'provincia', 'marca, modelo y año', 'foto del hueco'],
            'Hello' => ['Hello 👋', 'province', 'make, model and year', 'photo of the opening'],
        ] as $greeting => $parts) {
            $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
            $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => $greeting])
                ->assertOk()->assertJsonPath('mode', 'greeting');
            foreach ($parts as $part) $this->assertStringContainsString($part, $r->json('text'));
            $this->assertSame(3, substr_count($r->json('text'), "\n- "));
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_preview_standard_answer_preserves_handoff_then_other_questions_get_waiting_notice(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'it', 'standard_replies' => [[
            'title' => 'Contatti', 'topic' => 'Contatti', 'category' => 'general', 'enabled' => true,
            'answers' => ['it' => 'Scrivici a info@example.test.'],
        ]]]);
        $until = now()->timestamp + 43200;
        $this->withSession(['whatsapp_preview' => ['handoff_until' => $until, 'quote_state' => ['product' => 'selected-radio']]]);
        Mail::fake();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Qual è la vostra mail?'])
            ->assertOk()->assertJsonPath('mode', 'standard_general')->assertJsonPath('text', 'Scrivici a info@example.test.');
        $this->assertSame($until, session('whatsapp_preview.handoff_until'));
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Posso avere un preventivo?'])
            ->assertOk()->assertJsonPath('mode', 'operator_waiting');
        $this->assertSame($until, session('whatsapp_preview.handoff_until'));
        $this->assertSame('selected-radio', session('whatsapp_preview.quote_state.product'));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_preview_handoff_pauses_simulated_chat_until_reset(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Vorrei un operatore'])
            ->assertOk()->assertJsonPath('mode', 'handoff');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Hola!'])->assertOk()->assertJsonPath('mode', 'operator_waiting');
        $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Hola!'])
            ->assertOk()->assertJsonPath('mode', 'greeting');
        $this->assertFalse(Cache::store('file')->has('waha-test-handoff:'.app(WhatsAppAssistantSettings::class)->suffix()));
    }

    public function test_short_din_answers_in_all_languages_move_to_budget(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'auto']);
        foreach ([
            'quiero una universal' => ['uno' => '1DIN', 'dos' => '2DIN', '1' => '1DIN', '2' => '2DIN'],
            'vorrei una universale' => ['uno' => '1DIN', 'due' => '2DIN'],
            'Do you have universal radios?' => ['one' => '1DIN', 'two' => '2DIN'],
        ] as $request => $answers) {
            foreach ($answers as $answer => $din) {
                $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
                $this->postJson('/dashboard/whatsapp/preview', ['message' => $request])
                    ->assertOk()->assertJsonPath('mode', 'clarify');
                $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => (string) $answer])
                    ->assertOk()->assertJsonPath('mode', 'clarify');
                $this->assertStringNotContainsString('1 DIN o 2 DIN', $r->json('text'));
                $this->assertStringNotContainsString('1 DIN or 2 DIN', $r->json('text'));
                $this->assertMatchesRegularExpression('/budget|presupuesto/u', $r->json('text'));
                $this->assertSame($din, session('whatsapp_preview.quote_state.universal_din'));
            }
        }
        Http::assertNothingSent();
    }

    public function test_universal_typo_and_spoken_din_continue_to_budget_and_filtered_offer(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'auto', 'fallback_language' => 'es']);
        foreach (['single' => ['1DIN', 150], 'double' => ['2DIN', 150], 'expensive' => ['1DIN', 300]] as $handle => [$din, $price]) {
            $p = ConfiguratorProduct::create(['handle' => $handle, 'category' => 'screen', 'title' => 'Radio Universal '.$din,
                'model' => 'Universal', 'meta' => ['din' => $din], 'price_min' => $price]);
            $p->variants()->create(['title' => 'Standard', 'option_value' => 'Standard', 'price' => $price, 'shopify_variant_id' => '100']);
        }
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'avete radio universalil?'])
            ->assertOk()->assertJsonPath('mode', 'clarify')->assertJsonPath('text', 'Cerchi una radio 1 DIN o 2 DIN? Scrivi 1 DIN oppure 2 DIN.');
        foreach (['no voglio quella universale da un din', 'un din'] as $text) {
            $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => $text])
                ->assertOk()->assertJsonPath('mode', 'clarify');
            $this->assertStringContainsString('Qual è il tuo budget', $r->json('text'));
            $this->assertStringNotContainsString('Cerchi una radio', $r->json('text'));
        }
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => '100-200 euro'])
            ->assertOk()->assertJsonPath('mode', 'offer');
        $this->assertStringContainsString('/products/single', $r->json('text'));
        $this->assertStringNotContainsString('/products/double', $r->json('text'));
        $this->assertStringNotContainsString('/products/expensive', $r->json('text'));
        Http::assertNothingSent();
    }

    public function test_missing_radio_followups_do_not_resubmit_or_repeat_and_unknown_question_goes_to_operator(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'es']);
        ConfiguratorProduct::create(['handle' => 'fiat-old-loop', 'category' => 'screen', 'title' => 'Fiat 500',
            'brand' => 'FIAT', 'model' => '500', 'year_from' => 2007, 'year_to' => 2020]);
        Mail::shouldReceive('html')->once()->andReturnNull();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Fiat 500 2021'])
            ->assertOk()->assertJsonPath('mode', 'missing_submitted');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Muchas gracias'])
            ->assertOk()->assertJsonPath('mode', 'greeting')->assertJsonPath('text', '¡Gracias a ti!');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => '¿Cuánto cuesta el envío?'])
            ->assertOk()->assertJsonPath('mode', 'standard_general');
        Mail::shouldReceive('mailer')->with('smtp')->once()->andReturnSelf();
        Mail::shouldReceive('raw')->once()->withArgs(fn ($body, $callback) => str_contains($body, 'Fiat') || str_contains($body, 'FIAT'))
            ->andReturn(new \stdClass);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => '¿Y puedo poner una universal?'])
            ->assertOk()->assertJsonPath('mode', 'clarify')->assertJsonPath('text', '¿Buscas una radio 1 DIN o 2 DIN? Escribe 1 DIN o 2 DIN.');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => '2 DIN'])
            ->assertOk()->assertJsonPath('mode', 'clarify');
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => '100–200 euro'])
            ->assertOk()->assertJsonPath('mode', 'handoff')->assertJsonPath('vehicle.year', 2021);
        $this->assertStringContainsString('Verificaremos una radio universal', $r->json('text'));
        $this->postJson('/dashboard/whatsapp/preview', ['message' => '¿Y otra radio para Fiat 500 2021?'])->assertOk()->assertJsonPath('mode', 'operator_waiting');
        $this->assertDatabaseCount('missing_vehicle_requests', 1);
        Http::assertNothingSent();
    }

    public function test_new_vehicle_request_requires_reset_after_preview_operator_handoff(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'es']);
        ConfiguratorProduct::create(['handle' => 'fiat-new-request', 'category' => 'screen', 'title' => 'Fiat 500',
            'brand' => 'FIAT', 'model' => '500', 'year_from' => 2007, 'year_to' => 2020, 'price_min' => 300]);
        ConfiguratorProduct::create(['handle' => 'audi-new-request', 'category' => 'screen', 'title' => 'Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2010, 'price_min' => 350]);
        Mail::shouldReceive('html')->once()->andReturnNull();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Fiat 500 2021'])
            ->assertOk()->assertJsonPath('mode', 'missing_submitted');
        Mail::shouldReceive('mailer')->with('smtp')->once()->andReturnSelf();
        Mail::shouldReceive('raw')->once()->andReturn(new \stdClass);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => '¿Cuánto tardarán en buscarla?'])
            ->assertOk()->assertJsonPath('mode', 'handoff');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => '¿Y tenéis otra radio para Audi A4 2008?'])->assertOk()->assertJsonPath('mode', 'operator_waiting');
        $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => '¿Y tenéis otra radio para Audi A4 2008?'])
            ->assertOk()->assertJsonPath('mode', 'offer')->assertJsonPath('vehicle.brand', 'AUDI')->assertJsonPath('vehicle.year', 2008);
        $this->assertStringContainsString('/products/audi-new-request', $r->json('text'));
        $this->assertDatabaseCount('missing_vehicle_requests', 1);
        $this->assertEmpty(session('whatsapp_preview.form'));
        Http::assertNothingSent();
    }

    public function test_preview_missing_radio_sends_email_and_stores_real_record(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        ConfiguratorProduct::create([
            'handle' => 'audi-old', 'category' => 'screen', 'title' => 'Radio Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009,
        ]);
        Mail::shouldReceive('html')->once()->andReturnNull();
        foreach (['Radio per Audi A4 2020'] as $body) {
            $response = $this->postJson('/dashboard/whatsapp/preview', ['message' => $body])->assertOk();
        }
        $response->assertJsonPath('mode', 'missing_submitted')->assertJsonPath('text', 'Stiamo cercando lo schermo compatibile con il tuo modello. Verrai contattato il prima possibile.');
        $this->assertDatabaseCount('missing_vehicle_requests', 1);
        Http::assertNothingSent();
    }

    public function test_preview_missing_radio_keeps_vehicle_and_reports_email_failure_before_confirmation(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        ConfiguratorProduct::create(['handle' => 'fiat-old', 'category' => 'screen', 'title' => 'Fiat 500',
            'brand' => 'FIAT', 'model' => '500', 'year_from' => 2007, 'year_to' => 2020]);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'ciao ho una fiat 500 che modelli avete?'])
            ->assertOk()->assertJsonPath('mode', 'vehicle_details');
        Mail::shouldReceive('html')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'del 2021'])
            ->assertStatus(503)->assertJsonPath('message', 'Invio email della richiesta radio non riuscito. Controlla la configurazione email e riprova.');
        $this->assertDatabaseHas('missing_vehicle_requests', ['brand' => 'FIAT', 'model' => '500', 'year' => 2021, 'phone' => '34611509809']);
        Http::assertNothingSent();
    }

    public function test_smeg_question_sends_programmed_preview_reply_without_collecting_customer_data(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        ConfiguratorProduct::create([
            'handle' => 'citroen-cactus-other-years', 'category' => 'screen', 'title' => 'Radio Citroen C4 Cactus',
            'brand' => 'CITROEN', 'model' => 'C4 Cactus', 'year_from' => 2020, 'year_to' => 2025,
        ]);
        Mail::shouldReceive('html')->once()->andReturnNull();
        $message = 'Hola, tengo un Citroën C4 Cactus de 2014–2017 con pantalla original y sistema SMEG 5.4.B.R8 (CD 26378, fecha 01-12-2015). Utilizo un iPhone y quiero instalar Apple CarPlay inalámbrico manteniendo la pantalla, los mandos del volante, el Bluetooth y los sensores originales. ¿Me podéis confirmar qué referencia exacta es compatible con mi sistema? ¿Incluye Google Maps, Waze, Spotify y Siri? ¿Necesita entrada AUX activada? ¿Cuál es el precio con IVA, envío a Canarias e instalación, si está disponible?';
        $this->postJson('/dashboard/whatsapp/preview', ['message' => $message])
            ->assertOk()->assertJsonPath('mode', 'missing_submitted')
            ->assertJsonPath('text', 'Estamos buscando la pantalla compatible con tu modelo. Te contactaremos lo antes posible.')
            ->assertJsonPath('vehicle.year', 2014)
            ->assertSessionHas('whatsapp_preview.form.data.phone', '34611509809')
            ->assertSessionHas('whatsapp_preview.form.data.comment', "Richiesta radio specifica da WhatsApp:\n".$message);
        $this->assertDatabaseCount('missing_vehicle_requests', 1);
        Http::assertNothingSent();
    }

    public function test_preview_technical_escalation_simulates_email_and_pauses_only_preview(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        ConfiguratorProduct::create([
            'handle' => 'audi-technical', 'category' => 'screen', 'title' => 'Radio Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009, 'price_min' => 300,
        ]);
        $payload = fn ($answer, $insists) => ['status' => 'completed', 'output' => [[
            'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'answer' => $answer, 'unknown' => ['AUX'], 'needs_operator' => $insists,
            ])]],
        ]]];
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($payload('CarPlay è confermato.', false))
            ->push($payload('La necessità di AUX non è confermata.', true))]);
        Mail::shouldReceive('mailer')->with('smtp')->once()->andReturnSelf();
        Mail::shouldReceive('raw')->once()->andReturn(new \stdClass);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Radio per Audi A4 2001'])
            ->assertOk()->assertJsonPath('mode', 'offer');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Serve AUX? Qual è il prezzo?'])
            ->assertOk()->assertJsonPath('mode', 'ai');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Insisto: serve AUX?'])
            ->assertOk()->assertJsonPath('mode', 'technical_handoff');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Serve AUX?'])->assertOk()->assertJsonPath('mode', 'operator_waiting');
        $this->assertFalse(Cache::store('file')->has('waha-test-handoff:'.app(WhatsAppAssistantSettings::class)->suffix()));
    }

    public function test_gps_requires_coordinates_then_product_link_before_standard_answer(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['fallback_language' => 'es', 'standard_replies' => [[
            'enabled' => true, 'answers' => ['it' => 'Ha GPS.', 'es' => 'Tiene GPS.', 'en' => 'It has GPS.'],
        ]]]);
        ConfiguratorProduct::create(['handle' => 'outlander-radio', 'category' => 'screen', 'title' => 'Mitsubishi Outlander',
            'brand' => 'MITSUBISHI', 'model' => 'Outlander', 'year_from' => 2010, 'year_to' => 2020, 'price_min' => 300]);
        $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'GPS?'])
            ->assertOk()->assertJsonPath('mode', 'vehicle_details')->assertJsonMissing(['text' => 'Tiene GPS.']);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Mitsubishi'])
            ->assertOk()->assertJsonPath('mode', 'vehicle_details')->assertJsonPath('text', '¿Cuál es el modelo de tu coche?');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Outlnder'])
            ->assertOk()->assertJsonPath('mode', 'vehicle_details')->assertJsonPath('text', '¿De qué año es tu coche? Escribe el año con 4 cifras, por ejemplo 2016.');
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => '2013'])->assertOk()->assertJsonPath('mode', 'offer');
        $this->assertStringContainsString('/products/outlander-radio', $r->json('text'));
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'GPS?'])
            ->assertOk()->assertJsonPath('mode', 'standard')->assertJsonPath('text', 'Tiene GPS.');
        Http::assertNothingSent();
    }

    public function test_spanish_fallback_preserves_clear_language_and_ignores_mixed_ties(): void
    {
        $s = app(WhatsAppAssistantSettings::class);
        $s->save(['fallback_language' => 'es']);
        $this->assertSame('es', $s->locale(['Lexus rx450h aniversario del 2013']));
        $this->assertSame('it', $s->locale(['Buongiorno', 'RX450h 2013']));
        $this->assertSame('en', $s->locale(['Hello', 'hola ciao', 'RX450h']));
        $this->assertSame('es', $s->locale(['Hola', 'RX450h']));
    }

    public function test_general_information_answers_without_vehicle_or_catalog_and_category_is_saved(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rules = app(WhatsAppAssistantSettings::class)->get();
        $rules['standard_replies'] = [['title' => 'Negozio', 'category' => 'general', 'enabled' => true,
            'answers' => ['it' => 'Il negozio è a Roma.', 'es' => 'La tienda está en Roma.', 'en' => 'Our shop is in Rome.']]];
        $this->put('/dashboard/whatsapp', $rules)->assertSessionHasNoErrors();
        $this->assertSame('general', app(WhatsAppAssistantSettings::class)->get()['standard_replies'][0]['category']);
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        foreach (['Sove avete il negozio?' => 'Il negozio è a Roma.', '¿Dónde está la tienda?' => 'La tienda está en Roma.', 'Where is your shop?' => 'Our shop is in Rome.'] as $question => $answer) {
            $this->withSession(['whatsapp_preview' => ['last_activity' => now()->timestamp]]);
            $this->postJson('/dashboard/whatsapp/preview', ['message' => $question])
                ->assertOk()->assertJsonPath('mode', 'standard_general')->assertJsonPath('text', $answer);
        }
        Http::assertNothingSent();
        $rules['standard_replies'][0]['category'] = 'invalid';
        $this->put('/dashboard/whatsapp', $rules)->assertSessionHasErrors('standard_replies.0.category');
    }

    public function test_accessory_followup_uses_standard_delivery_time_without_ai_or_operator_email(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $title = 'Cámara Trasera Universal AHD CVBS Gran Angular';
        ConfiguratorProduct::create(['handle' => 'camera-followup', 'category' => 'camera', 'title' => $title, 'price_min' => 32.94]);
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Hola quiero comprar "'.$title.'"'])
            ->assertOk()->assertJsonPath('mode', 'accessory_offer');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => '¿Hacen envío a otra isla?'])
            ->assertOk()->assertJsonPath('mode', 'standard_general');
        Mail::fake();
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => '¿Cuándo llega la cámara?'])
            ->assertOk()->assertJsonPath('mode', 'standard_general')->assertJsonPath('matches', 0);
        $this->assertStringNotContainsString('marca', $r->json('text'));
        $this->assertStringContainsString('10–15 días', $r->json('text'));
        $this->assertSame('camera-followup', session('whatsapp_preview.quote_state.accessory_handle'));
        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertDatabaseCount('missing_vehicle_requests', 0);
    }

    public function test_installation_area_reply_uses_configured_zones_and_keeps_known_vehicle(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['language' => 'es', 'standard_replies' => [['title' => 'Installazione', 'topic' => 'Installazione', 'category' => 'general',
            'enabled' => true, 'answers' => ['es' => 'Podemos ponerte en contacto con un instalador. ¿En qué zona estás?']]]]);
        $product = ConfiguratorProduct::create(['handle' => 'vw-up-2017', 'category' => 'screen', 'title' => 'Volkswagen Up',
            'brand' => 'VOLKSWAGEN', 'model' => 'Up', 'year_from' => 2017, 'year_to' => 2023, 'price_min' => 400]);
        foreach (['4core 2G 32G' => 100, '8core 6G 128G' => 300, '8core 8G 256G' => 400] as $option => $price) {
            $product->variants()->create(['title' => $option, 'option_value' => $option, 'price' => $price, 'shopify_variant_id' => (string) $price]);
        }
        ConfiguratorProduct::create(['handle' => 'vw-polo', 'category' => 'screen', 'title' => 'Volkswagen Polo',
            'brand' => 'VOLKSWAGEN', 'model' => 'Polo', 'year_from' => 2010, 'year_to' => 2023, 'price_min' => 300]);
        $zone = InstallationZone::create(['name' => 'Gran Canaria', 'active' => true]);
        $zone->postalCodes()->create(['postal_code_from' => '35001', 'postal_code_to' => '35020']);
        $zone->services()->create(['name' => 'Pantalla', 'price' => 50]);
        $zone->services()->create(['name' => 'Pantalla + cámara trasera', 'price' => 100]);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Radio para VOLKSWAGEN UP 1.0 MOVE UP 75'])
            ->assertOk()->assertJsonPath('mode', 'vehicle_details');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'El modelo es VOLKSWAGEN UP! 1.0 MOVE 2017'])
            ->assertOk()->assertJsonPath('mode', 'offer');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'instala usted?'])
            ->assertOk()->assertJsonPath('mode', 'standard_general');
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Buenos días. Estoy en Las Palmas de Gran Canaria'])
            ->assertOk()->assertJsonPath('mode', 'standard_general')->assertJsonPath('vehicle.model', 'Up')->assertJsonPath('vehicle.year', 2017);
        $this->assertStringContainsString('50,00 €', $r->json('text'));
        $this->assertStringContainsString('100,00 €', $r->json('text'));
        $this->assertStringNotContainsString('año', $r->json('text'));
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Buenos días. Me gustaría que, si pudiera ser, me preparasen presupuesto con instalación incluida de una pantalla compatible con Volswagen Up (2017) que tenga car play principalmente con las funcionalidades de llamadas, gps, waze y bluetooh. Dentro de esas características pues la más económica. Muchas gracias.'])
            ->assertOk()->assertJsonPath('mode', 'quote')->assertJsonPath('vehicle.year', 2017);
        $this->assertStringContainsString('8core 6G 128G', $r->json('text'));
        $this->assertStringContainsString('Instalación — Gran Canaria: 50,00 €', $r->json('text'));
        $this->assertStringContainsString('Total presupuesto: 344,00 €', $r->json('text'));
        $this->assertStringContainsString('Pago solo radio:', $r->json('text'));
        $this->assertStringContainsString('344,00 €', $r->json('document.caption'));
        $this->assertSame('Descargar presupuesto PDF', $r->json('document.download_label'));
        $this->get($r->json('document.download_url'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseCount('italian_orders', 0);
        Http::assertNothingSent();
    }

    public function test_quote_with_installation_asks_only_unknown_zone_then_generates_pdf(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'es']);
        $zone = InstallationZone::create(['name' => 'Gran Canaria', 'active' => true]);
        $zone->services()->create(['name' => 'Pantalla', 'price' => 50]);
        $p = ConfiguratorProduct::create(['handle' => 'installation-quote', 'category' => 'screen', 'title' => 'Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009, 'price_min' => 300]);
        $p->variants()->create(['title' => 'Base', 'price' => 300, 'shopify_variant_id' => '300']);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Presupuesto Audi A4 2001 con instalación'])
            ->assertOk()->assertJsonPath('mode', 'quote_choice')->assertJsonPath('text', '¿En qué zona quieres la instalación? Escribe la localidad o el código postal de 5 cifras, por ejemplo Telde o 35200.');
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Gran Canaria'])
            ->assertOk()->assertJsonPath('mode', 'quote');
        $this->assertStringContainsString('Total presupuesto: 344,00 €', $r->json('text'));
        $this->assertNotNull($r->json('document'));
        Http::assertNothingSent();
    }

    public function test_price_with_installation_generates_quote_for_selected_variant_without_reasking(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $zone = InstallationZone::create(['name' => 'Gran Canaria', 'active' => true]);
        $zone->services()->create(['name' => 'Pantalla', 'price' => 50]);
        $p = ConfiguratorProduct::create(['handle' => 'mercedes-sl-2012', 'category' => 'screen', 'title' => 'Mercedes SL R230 2008-2012',
            'brand' => 'MERCEDES', 'model' => 'SL', 'year_from' => 2008, 'year_to' => 2012, 'price_min' => 300]);
        $p->variants()->create(['title' => '8core 6G 64G', 'option_value' => '8core 6G 64G', 'price' => 300, 'shopify_variant_id' => '111']);
        $v = $p->variants()->create(['title' => '8core 8G 128G', 'option_value' => '8core 8G 128G', 'price' => 400, 'shopify_variant_id' => '222']);
        Mail::fake();
        foreach ([
            'es' => "Me interesa la 8core/ 8G/ 128G\nCuál seria el precio con instalación?",
            'it' => 'Mi interessa la 8core/ 8G/ 128G. Qual è il prezzo con installazione?',
            'en' => 'I want the 8core/ 8G/ 128G. What is the price with installation?',
        ] as $locale => $text) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
            $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Mercedes SL 2012'])
                ->assertOk()->assertJsonPath('mode', 'offer');
            $this->withSession(['whatsapp_preview.quote_state' => ['installation_zone' => $zone->id]]);
            $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => $text])
                ->assertOk()->assertJsonPath('mode', 'quote');
            $this->assertStringContainsString('8core 8G 128G', $r->json('text'));
            $this->assertStringContainsString('Gran Canaria: 50,00 €', $r->json('text'));
            $this->assertSame($v->id, session('whatsapp_preview.quote_state.variant_id'));
            $checkout = app(\App\Services\ItalianCheckout::class);
            $items = $checkout->normalizeItems(['items' => [['type' => 'variant', 'id' => $v->id, 'quantity' => 1]]]);
            $quote = $checkout->quote($items, locale: $locale === 'it' ? 'it' : 'es');
            $total = number_format(($quote['total_amount'] + 5000) / 100, 2, ',', '.').' €';
            $this->assertStringContainsString($total, $r->json('text'));
            $this->assertNotNull($r->json('document'));
        }
        $this->assertDatabaseCount('italian_orders', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_stock_followup_uses_supplier_policy_without_ai_or_operator_in_each_language(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Mail::fake();
        foreach ([
            'es' => "Genial, me interesa\nLa tenéis en stock?\nO tenéis que pedirla\nMi coche no tiene fibra óptica, acabo de comprobarlo",
            'it' => 'Mi interessa, è disponibile in stock o dovete ordinarla? La mia auto non ha fibra ottica.',
            'en' => 'I am interested. Is it in stock or do you need to order it? My car has no optical fibre.',
        ] as $locale => $text) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
            $this->withSession(['whatsapp_preview' => ['last_activity' => now()->timestamp,
                'vehicle' => ['brand' => 'MERCEDES', 'model' => 'SL', 'year' => 2011],
                'quote_state' => ['installation_zone' => 1, 'variant_id' => 999, 'product' => 'selected-radio']]]);
            $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => $text])
                ->assertOk()->assertJsonPath('mode', 'standard_general');
            $this->assertStringContainsString('10–15', $r->json('text'));
            $this->assertNull(session('whatsapp_preview.handoff_until'));
            $this->assertSame('selected-radio', session('whatsapp_preview.quote_state.product'));
            $this->assertSame(999, session('whatsapp_preview.quote_state.variant_id'));
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_explicit_stock_for_selected_variant_overrides_supplier_policy(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'es']);
        $p = ConfiguratorProduct::create(['handle' => 'stock-radio', 'category' => 'screen', 'title' => 'Radio']);
        $v = $p->variants()->create(['title' => '8G 128G', 'shopify_variant_id' => '123', 'price' => 400]);
        $other = $p->variants()->create(['title' => '4G 64G', 'shopify_variant_id' => '456', 'price' => 300]);
        $stock = \App\Models\StockProduct::create(['product_handle' => $p->handle, 'quantity' => 1,
            'discount_variant_key' => \App\Models\StockProduct::variantKey($other)]);
        $state = ['product' => $p->handle, 'variant_id' => $v->id];
        $bot = app(WhatsAppTestBot::class);
        $this->assertStringContainsString('10–15', $bot->answer('¿Está en stock?', [], [], null, $state)['text']);
        $stock->update(['discount_variant_key' => \App\Models\StockProduct::variantKey($v)]);
        $this->assertSame('La radio seleccionada figura disponible en stock.', $bot->answer('¿Está en stock?', [], [], null, $state)['text']);
        $stock->update(['quantity' => 0]);
        $this->assertStringContainsString('10–15', $bot->answer('¿Está en stock?', [], [], null, $state)['text']);
        Http::assertNothingSent();
    }

    public function test_installation_unknown_locality_requests_only_postal_code_and_ignores_inactive_zones(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'es']);
        $zone = InstallationZone::create(['name' => 'Gran Canaria', 'active' => true]);
        $zone->postalCodes()->create(['postal_code_from' => '35001', 'postal_code_to' => '35020']);
        $zone->services()->create(['name' => 'Pantalla', 'price' => 50]);
        InstallationZone::create(['name' => 'Zona cerrada', 'active' => false]);
        $vehicle = ['brand' => 'VOLKSWAGEN', 'model' => 'Up', 'year' => 2017];
        $this->withSession(['whatsapp_preview' => ['vehicle' => $vehicle, 'last_activity' => now()->timestamp,
            'history' => [['role' => 'assistant', 'content' => '¿En qué zona estás?']]]]);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Zona cerrada'])
            ->assertOk()->assertJsonPath('text', '¿Cuál es el código postal de tu zona? Escribe las 5 cifras, por ejemplo 35200.');
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => '35010'])
            ->assertOk()->assertJsonPath('mode', 'standard_general')->assertJsonPath('vehicle.year', 2017);
        $this->assertStringContainsString('Gran Canaria', $r->json('text'));
        $this->assertStringContainsString('50,00 €', $r->json('text'));
        Http::assertNothingSent();
    }

    public function test_shipping_cost_policy_answers_locally_in_all_languages_without_vehicle_or_operator(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        Mail::shouldReceive('mailer')->never();
        foreach ([
            'El precio es el que figura en la web y supongo más gastos de envío, que no tengo ni idea de cuánto puede ser' => 'es',
            '¿Cuánto cuesta el envío fuera de Canarias?' => 'es',
            'Quanto costa la spedizione alle Canarie?' => 'it',
            'How much does shipping to England cost?' => 'en',
        ] as $question => $locale) {
            $this->withSession(['whatsapp_preview' => ['last_activity' => now()->timestamp]]);
            $this->postJson('/dashboard/whatsapp/preview', ['message' => $question])
                ->assertOk()->assertJsonPath('mode', 'standard_general')
                ->assertJsonPath('text', app(WhatsAppStandardReplies::class)->shippingPolicy($locale, [$question]));
        }
        Http::assertNothingSent();
    }

    public function test_shipping_availability_is_positive_without_vehicle_even_with_store_context(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        foreach ([
            'Bien, por lo que veo vuestra tienda está en Mogán, la pregunta es si hacen envío a otra isla' => 'Sí, hacemos envíos a cualquier destino, también a otras islas.',
            'Spedite anche in Australia?' => 'Sì, spediamo ovunque, anche nelle altre isole.',
            'Do you ship to another island?' => 'Yes, we ship worldwide, including to other islands.',
            '¿Hacéis envíos a Tenerife?' => 'Sí, hacemos envíos a cualquier destino, también a otras islas.',
        ] as $question => $answer) {
            $this->withSession(['whatsapp_preview' => ['last_activity' => now()->timestamp]]);
            $this->postJson('/dashboard/whatsapp/preview', ['message' => $question])
                ->assertOk()->assertJsonPath('mode', 'standard_general')->assertJsonPath('text', $answer)->assertJsonPath('matches', 0);
        }
        Http::assertNothingSent();
    }

    public function test_payment_and_instalment_questions_use_general_reply_in_three_languages(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $answers = ['it' => 'PayPal, carte e bonifico. Verifica le opzioni per le rate al pagamento.',
            'es' => 'PayPal, tarjetas y transferencia. Comprueba las opciones de pago a plazos al finalizar el pedido.',
            'en' => 'PayPal, cards and bank transfer. Check instalment options at checkout.'];
        app(WhatsAppAssistantSettings::class)->save(['standard_replies' => [['title' => 'Metodi di pagamento', 'topic' => 'Pagamenti',
            'category' => 'general', 'enabled' => true, 'answers' => $answers]]]);
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        foreach (['posso pagare a rate? che metodi di pagamento avete?' => 'it', '¿Puedo pagar a plazos? ¿Qué métodos de pago tenéis?' => 'es',
            'What payment methods do you accept? Can I pay in instalments?' => 'en'] as $message => $locale) {
            $this->withSession(['whatsapp_preview' => ['last_activity' => now()->timestamp]]);
            $this->postJson('/dashboard/whatsapp/preview', ['message' => $message])
                ->assertOk()->assertJsonPath('mode', 'standard_general')->assertJsonPath('text', $answers[$locale]);
        }
        Http::assertNothingSent();
    }

    public function test_installation_questions_use_dedicated_general_reply_in_all_languages(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['standard_replies' => [
            ['title' => 'luogo lavoro invio', 'category' => 'general', 'enabled' => true,
                'answers' => ['it' => 'Collaboriamo con un installatore.', 'es' => 'Tenemos un instalador.', 'en' => 'We have an installer.']],
            ['topic' => 'Installazione', 'title' => 'Installazione', 'category' => 'general', 'enabled' => true,
                'answers' => ['it' => 'In quale zona sei?', 'es' => '¿En qué zona estás?', 'en' => 'Which area are you in?']],
        ]]);
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        foreach (['buongiorno fate installazioni?' => 'In quale zona sei?', 'Installate anche voi?' => 'In quale zona sei?', 'potete anceh installarla?' => 'In quale zona sei?', 'me la potete anche instalare?' => 'In quale zona sei?', 'potete instalarla?' => 'In quale zona sei?',
            '¿Hacéis instalaciones?' => '¿En qué zona estás?', 'instala usted?' => '¿En qué zona estás?', '¿La instalas?' => '¿En qué zona estás?', '¿Puede instalarla?' => '¿En qué zona estás?', 'Do you install?' => 'Which area are you in?', 'Do you offer installations?' => 'Which area are you in?'] as $question => $answer) {
            $this->withSession(['whatsapp_preview' => ['last_activity' => now()->timestamp]]);
            $this->postJson('/dashboard/whatsapp/preview', ['message' => $question])
                ->assertOk()->assertJsonPath('mode', 'standard_general')->assertJsonPath('text', $answer);
        }
        Http::assertNothingSent();
    }

    public function test_universal_installation_uses_standard_reply_then_local_zone_without_ai_or_email(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $reply = 'Normalmente sì, il costo è di 50 €, ma dipende dalla zona. Da dove ci scrivi?';
        app(WhatsAppAssistantSettings::class)->save(['language' => 'it', 'standard_replies' => [
            ['topic' => 'Installazione', 'title' => 'Installazione', 'category' => 'general', 'enabled' => true, 'answers' => ['it' => $reply]],
        ]]);
        foreach (['fits' => 'Radio 1DIN', 'conflicting' => 'Radio 2 DIN'] as $handle => $title) {
            $p = ConfiguratorProduct::create(['handle' => $handle, 'category' => 'screen', 'title' => $title,
                'model' => 'Universal', 'meta' => ['din' => '1DIN'], 'price_min' => 150]);
            $p->variants()->create(['title' => 'Standard', 'option_value' => 'Standard', 'price' => 150, 'shopify_variant_id' => '100']);
        }
        $zone = InstallationZone::create(['name' => 'Gran Canaria', 'active' => true]);
        $zone->services()->create(['name' => 'Radio', 'price' => 50]);
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Radio universale un din 100-200 euro'])
            ->assertOk()->assertJsonPath('mode', 'offer');
        $this->assertStringNotContainsString('/products/conflicting', $r->json('text'));
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'me la potete anche instalare?'])
            ->assertOk()->assertJsonPath('mode', 'standard_general')->assertJsonPath('text', $reply);
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Gran Canaria'])
            ->assertOk()->assertJsonPath('mode', 'standard_general');
        $this->assertStringContainsString('50,00 €', $r->json('text'));
        Http::assertNothingSent();
    }

    public function test_installation_followup_cannot_resume_a_paused_preview(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(WhatsAppAssistantSettings::class)->save(['language' => 'es', 'standard_replies' => [
            ['topic' => 'Installazione', 'category' => 'general', 'enabled' => true,
                'answers' => ['es' => 'Normalmente sí, cuesta 50 €, pero depende de la zona. ¿Desde dónde nos escribes?']],
        ]]);
        $zone = InstallationZone::create(['name' => 'Gran Canaria', 'active' => true]);
        $zone->services()->create(['name' => 'Radio', 'price' => 50]);
        Mail::fake();
        foreach ([false, true] as $pending) {
            $this->withSession(['whatsapp_preview' => ['handoff_until' => now()->timestamp + 43200,
                'last_activity' => now()->timestamp, 'technical' => ['unknown' => ['Installation']],
                'quote_state' => ['installation_pending' => $pending]]]);
            $this->postJson('/dashboard/whatsapp/preview', ['message' => 'instala usted?'])->assertOk()->assertJsonPath('mode', 'standard_general');
            $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Gran Canaria'])->assertOk()->assertJsonPath('mode', 'operator_waiting');
            $this->assertNotNull(session('whatsapp_preview.handoff_until'));
            $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
            $this->postJson('/dashboard/whatsapp/preview', ['message' => 'instala usted?'])
                ->assertOk()->assertJsonPath('mode', 'standard_general');
            $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Gran Canaria'])
                ->assertOk()->assertJsonPath('mode', 'standard_general');
            $this->assertStringContainsString('50,00 €', $r->json('text'));
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_installation_uses_las_palmas_from_the_initial_message_in_all_languages(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $zone = InstallationZone::create(['name' => 'Gran Canaria', 'active' => true]);
        $zone->services()->create(['name' => 'Radio', 'price' => 50]);
        Mail::fake();
        foreach ([
            'es' => 'Hola! Me gustaría ponerle una radio android a un Mercedes SL 500 (r230), vosotros instaláis radios? Estoy en Las Palmas',
            'it' => 'Ciao! Vorrei una radio Android per Mercedes SL 500 (r230), installate radio? Sono a Las Palmas',
            'en' => 'Hello! I want an Android radio for a Mercedes SL 500 (r230), do you install radios? I am in Las Palmas',
        ] as $locale => $text) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
            $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => $text])
                ->assertOk()->assertJsonPath('mode', 'standard_general');
            $this->assertStringContainsString('Gran Canaria', $r->json('text'));
            $this->assertStringContainsString('50,00 €', $r->json('text'));
            $this->assertStringNotContainsString('?', $r->json('text'));
            $this->assertSame($zone->id, session('whatsapp_preview.quote_state.installation_zone'));
        }
        $this->assertFalse($zone->matchesLocation('Las Palmasextra'));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_mixed_radio_and_installation_request_asks_only_year_then_offers_matching_radio(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $zone = InstallationZone::create(['name' => 'Gran Canaria', 'active' => true]);
        $zone->services()->create(['name' => 'Radio', 'price' => 50]);
        $p = ConfiguratorProduct::create(['handle' => 'mercedes-sl-radio', 'category' => 'screen', 'title' => 'Mercedes SL R230 2001-2007',
            'brand' => 'MERCEDES', 'model' => 'SL', 'year_from' => 2001, 'year_to' => 2007, 'price_min' => 200]);
        $p->variants()->create(['title' => '8core 6G', 'option_value' => '8core 6G', 'price' => 200, 'shopify_variant_id' => '100']);
        Mail::fake();
        foreach ([
            'es' => ['Hola! Me gustaría ponerle una radio android a un Mercedes SL 500 (r230), vosotros instaláis radios? Estoy en Las Palmas', '¿De qué año es tu coche? Escribe el año con 4 cifras, por ejemplo 2016.'],
            'it' => ['Ciao! Vorrei una radio Android per Mercedes SL 500 (r230), installate radio? Sono a Las Palmas', 'Di che anno è la tua auto? Scrivi l’anno con 4 cifre, ad esempio 2016.'],
            'en' => ['Hello! I want an Android radio for Mercedes SL 500 (r230), do you install radios? I am in Las Palmas', 'What year is your car? Write the 4-digit year, for example 2016.'],
        ] as $locale => [$text, $question]) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $this->postJson('/dashboard/whatsapp/preview/reset')->assertOk();
            $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => $text])
                ->assertOk()->assertJsonPath('mode', 'vehicle_details')
                ->assertJsonPath('vehicle.brand', 'MERCEDES')->assertJsonPath('vehicle.model', 'SL');
            $this->assertStringContainsString('50,00 €', $r->json('text'));
            $this->assertStringContainsString('Gran Canaria', $r->json('text'));
            $this->assertStringContainsString($question, $r->json('text'));
            $this->assertStringNotContainsString('/products/', $r->json('text'));
            $this->assertSame($zone->id, session('whatsapp_preview.quote_state.installation_zone'));
            $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => '2005'])
                ->assertOk()->assertJsonPath('mode', 'offer')->assertJsonPath('vehicle.year', 2005);
            $this->assertStringContainsString('/products/mercedes-sl-radio', $r->json('text'));
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_custom_reply_matching_tolerates_typos_plural_and_greetings_without_ai(): void
    {
        $service = app(WhatsAppStandardReplies::class);
        $replies = [['title' => 'Pagamento rateale', 'enabled' => true, 'answers' => ['it' => 'Risposta salvata.'],
            'questions' => ['it' => 'Posso pagare in tre rate?']]];
        foreach (['Buongiorno pagamento rateali?', 'Ciao posso pagrae in tre rate?'] as $question) {
            $this->assertSame('Risposta salvata.', $service->match($question, $replies));
        }
        $this->assertNull($service->match('Vorrei modificare il pagamento', $replies));
        Http::assertNothingSent();
    }

    public function test_unclear_request_emails_operator_and_pauses_preview_without_asking_vehicle(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Mail::shouldReceive('mailer')->with('smtp')->once()->andReturnSelf();
        Mail::shouldReceive('raw')->once()->withArgs(fn ($body, $callback) => str_contains($body, '34611509809') && str_contains($body, 'xyz boh questa cosa'))
            ->andReturn(new \stdClass);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'xyz boh questa cosa'])
            ->assertOk()->assertJsonPath('mode', 'handoff');
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'ciao'])->assertOk()->assertJsonPath('mode', 'operator_waiting');
        Http::assertNothingSent();
    }

    public function test_legacy_function_replies_remain_product_and_legacy_installation_is_general(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['standard_replies' => [
            ['enabled' => true, 'answers' => ['it' => 'Ha GPS.']],
            ['topic' => 'Installazione', 'enabled' => true, 'answers' => ['it' => 'Possiamo indicarti un installatore.']],
        ]]);
        $replies = $settings->get()['standard_replies'];
        $this->assertSame('product', $replies[0]['category']);
        $this->assertSame('general', $replies[1]['category']);
    }

    public function test_quote_lists_all_sizes_then_selected_radio_uses_known_year_and_calculates_total(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $p = ConfiguratorProduct::create(['handle' => 'mercedes-sl-7', 'category' => 'screen', 'title' => 'Radio 7” Mercedes SL R230 2001–2011',
            'brand' => 'MERCEDES', 'model' => 'SL', 'year_from' => 2001, 'year_to' => 2011, 'price_min' => 457]);
        $p->variants()->create(['title' => 'Linux SL-01-04-A', 'option_value' => 'Linux SL-01-04-A', 'price' => 457, 'shopify_variant_id' => '111']);
        $p->variants()->create(['title' => 'Linux SL-05-11-B', 'option_value' => 'Linux SL-05-11-B', 'price' => 457, 'shopify_variant_id' => '222']);
        ConfiguratorProduct::create(['handle' => 'mercedes-sl-9', 'category' => 'screen', 'title' => 'Radio 9” Mercedes SL',
            'brand' => 'MERCEDES', 'model' => 'SL', 'year_from' => 2001, 'year_to' => 2011, 'price_min' => 300]);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Mercedes SL 2010'])->assertOk();
        $this->postJson('/dashboard/whatsapp/preview', ['message' => "mi faresti un preventivo per quellada 7 ''"])
            ->assertOk()->assertJsonPath('mode', 'quote_choice')
            ->assertJsonFragment(['text' => "Quale radio preferisci?\n1. Radio 7” Mercedes SL R230 2001–2011\n2. Radio 9” Mercedes SL\n\nPer favore, scrivi solo 1 o 2."]);
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => '1'])
            ->assertOk()->assertJsonPath('mode', 'quote');
        $text = $r->json('text');
        $this->assertNotNull($r->json('document'));
        $download = $this->get($r->json('document.download_url'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $bytes = file_get_contents($download->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('/cart/222:1?discount=Base', $bytes);
        $this->assertStringContainsString('Linux SL-05-11-B', $text);
        $this->assertStringNotContainsString('Linux SL-01-04-A', $text);
        $this->assertStringContainsString('457,00 €', $text);
        $this->assertStringContainsString('447,86 €', $text);
        $this->assertStringContainsString('/cart/222:1?discount=Base', $text);
        $this->assertStringNotContainsString('?', str_replace('?discount=Base', '', $text));
        Http::assertNothingSent();
    }

    public function test_quote_asks_only_variant_then_quotes_the_chosen_version_without_ai(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $p = ConfiguratorProduct::create(['handle' => 'audi-7-quote', 'category' => 'screen', 'title' => 'Radio 7” Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009, 'price_min' => 300]);
        $p->variants()->create(['title' => '4G 64G', 'option_value' => '4G 64G', 'price' => 300, 'shopify_variant_id' => '333']);
        $p->variants()->create(['title' => '8G 128G', 'option_value' => '8G 128G', 'price' => 600, 'shopify_variant_id' => '444']);
        $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Audi A4 2001'])->assertOk();
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Vorrei un preventivo per la radio da 7 pollici'])
            ->assertOk()->assertJsonPath('mode', 'quote');
        $this->assertStringContainsString('4G 64G', $r->json('text'));
        $this->assertNotNull($r->json('document'));
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Preventivo versione 8G 128G'])->assertOk()->assertJsonPath('mode', 'quote');
        $this->assertStringContainsString('582,00 €', $r->json('text'));
        $this->assertStringContainsString('/cart/444:1?discount=Pro', $r->json('text'));
        $this->assertDatabaseCount('italian_orders', 0);
        Http::assertNothingSent();
    }

    public function test_whatsapp_pdf_uses_configurator_template_translations_and_separate_payment_totals(): void
    {
        $html = app(WhatsAppQuotePdf::class)->html([
            'context' => ['vehicle' => ['brand' => 'VOLKSWAGEN', 'model' => 'Up', 'year' => 2017]],
            'quote' => ['items' => [
                ['title' => 'Radio <test>', 'variant_title' => '8core 6G', 'quantity' => 1, 'total_amount' => 30000],
                ['title' => 'Instalación Gran Canaria', 'variant_title' => null, 'quantity' => 1, 'total_amount' => 5000],
            ], 'subtotal_amount' => 35000, 'discount_amount' => 600, 'discount_label' => 'Descuento Base 2%', 'total_amount' => 34400],
            'installation' => ['amount' => 5000], 'payment_url' => 'https://www.autoradiocanario.com/cart/300:1',
        ], 'es', 'ARC-TEST');
        foreach (['AUTORADIOCANARIO', 'Avenida Mencey 49', 'class="vehicle-band"', 'Precio unitario',
            'Coste total del proyecto', 'Total a pagar online', '344,00 €', '294,00 €', 'Radio &lt;test&gt;', 'ARC-TEST'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('Radio <test>', $html);
    }

    public function test_quote_pdf_is_admin_only_private_and_expires(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $p = ConfiguratorProduct::create(['handle' => 'pdf-radio', 'category' => 'screen', 'title' => 'Radio 7” Audi A4',
            'brand' => 'AUDI', 'model' => 'A4', 'year_from' => 2000, 'year_to' => 2009, 'price_min' => 300]);
        $p->variants()->create(['title' => 'Base', 'option_value' => 'Base', 'price' => 300, 'shopify_variant_id' => '123']);
        $r = $this->postJson('/dashboard/whatsapp/preview', ['message' => 'Preventivo Audi A4 2001'])
            ->assertOk()->assertJsonPath('mode', 'quote');
        $url = $r->json('document.download_url');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/dashboard/whatsapp/quotes/'.Str::uuid().'/pdf')->assertNotFound();
        $this->travel(25)->hours();
        $this->get($url)->assertNotFound();
        $this->travelBack();
        Http::assertNothingSent();
    }
}
