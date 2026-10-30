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

    private array $polledMessages = [];

    private bool $lidMatches = true;

    private bool $aiComplete = true;

    private array $aiPayloads = [];

    private ?\Closure $onAi = null;

    public function test_email_questions_use_saved_contact_reply_in_customer_language_without_ai(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['language' => 'auto', 'fallback_language' => 'es', 'standard_replies' => [[
            'category' => 'general', 'title' => 'info indirizzo', 'enabled' => true,
            'questions' => ['it' => "quale e' vostro indirizzo? o mail o telefono", 'es' => '', 'en' => ''],
            'answers' => ['it' => 'Indirizzo: Avenida Mencey 49. Scrivici a info@autoradioitaliano.it', 'es' => 'Dirección: Avenida Mencey 49. Escríbenos a info@autoradiocanario.com', 'en' => 'Address: Avenida Mencey 49. Email us at info@autoradiocanario.com'],
        ]]]);
        foreach (['quale e la vostra mail?' => 'it', 'Qual è il vostro indirizzo email?' => 'it',
            '¿Cuál es vuestro correo electrónico?' => 'es', '¿Cuál es vuestra dirección de email?' => 'es',
            'What is your email address?' => 'en'] as $question => $locale) {
            $r = app(WhatsAppTestBot::class)->conversationAnswer($question, [], [], null, true);
            $this->assertSame('standard_general', $r['mode'], $question);
            $this->assertStringContainsString($settings->get()['standard_replies'][0]['answers'][$locale], $r['text']);
            if ($locale === 'it') $this->assertStringNotContainsString('Bienvenido', $r['text']);
        }
        Http::assertNothingSent();
    }

    public function test_buenas_greets_in_spanish_and_allows_vehicle_followup(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'skoda-octavia', 'title' => 'Radio Skoda Octavia 2004-2013',
            'category' => 'screen', 'brand' => 'SKODA', 'model' => 'OCTAVIA', 'year_from' => 2004, 'year_to' => 2013]);
        $p->variants()->create(['title' => '8core 4G 64G', 'price' => 300]);
        $bot = app(WhatsAppTestBot::class);
        foreach (['Buenas', '¡Buenas!', 'Buenas tardes', 'Buenas noches'] as $text) {
            $r = $bot->conversationAnswer($text, [], [], null, true);
            $this->assertSame('greeting', $r['mode']);
            $this->assertStringContainsString('Por favor indícanos', $r['text']);
            $this->assertArrayNotHasKey('alert', $r);
            $history = [['role' => 'user', 'content' => $text], ['role' => 'assistant', 'content' => $r['text']]];
            $next = $bot->conversationAnswer('Es un Skoda octavia 1.9 tdci del 2007', $history);
            $this->assertSame('offer', $next['mode']);
            $this->assertStringContainsString('/products/skoda-octavia', $next['text']);
        }
        Http::assertNothingSent();
    }

    public function test_shipping_costs_use_customer_destination_without_comparing_regions(): void
    {
        app(WhatsAppAssistantSettings::class)->save(['language' => 'es']);
        $bot = app(WhatsAppTestBot::class);
        $question = 'Pero el envío cuanto sería?';
        $r = $bot->answer($question);
        $this->assertStringContainsString('¿En qué localidad', $r['text']);
        $this->assertTrue($r['quote_state']['shipping_pending']);
        foreach (['Madrid' => false, 'Gran Canaria' => true] as $town => $free) {
            $history = [['role' => 'user', 'content' => $question], ['role' => 'assistant', 'content' => $r['text']]];
            $next = $bot->answer('Estoy en '.$town, $history, [], null, $r['quote_state']);
            $this->assertFalse($next['quote_state']['shipping_pending']);
            $this->assertStringContainsString($free ? 'envío gratuito' : 'pantalla de envío', $next['text']);
            $this->assertStringNotContainsString('Canarias', $next['text']);
            $known = $bot->answer($question, [['role' => 'user', 'content' => 'Estoy en '.$town]]);
            $this->assertSame($next['text'], $known['text']);
        }
        Http::assertNothingSent();
    }

    public function test_tax_only_question_is_answered_without_vehicle_search(): void
    {
        $this->mock(WhatsAppCatalogSearch::class)->shouldNotReceive('search');
        $bot = app(WhatsAppTestBot::class);
        foreach ([
            'es' => 'Estoy en Canarias, el precio incluye el IVA, entiendo que el IVA se deduce y cambiaría el precio, ¿verdad?',
            'it' => 'Il prezzo include IVA? Sono alle Canarie, devo sottrarre IVA?',
            'en' => 'Is VAT included? I am in the Canary Islands, should I deduct VAT?',
        ] as $locale => $text) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $r = $bot->conversationAnswer($text);
            $this->assertSame('standard_general', $r['mode']);
            $this->assertSame(app(\App\Services\WhatsAppStandardReplies::class)->taxPolicy($locale), $r['text']);
            $this->assertArrayNotHasKey('form', $r);
            $this->assertArrayNotHasKey('alert', $r);
            $waiting = $bot->waitingAnswer($text);
            $this->assertSame('standard_general', $waiting['mode']);
            $this->assertSame($r['text'], $waiting['text']);
        }
        Http::assertNothingSent();
    }

    public function test_vehicle_search_uses_table_fields_instead_of_product_title(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'correct-fields', 'title' => 'Radio Ford Focus 2000-2005',
            'category' => 'screen', 'brand' => 'VOLKSWAGEN', 'model' => 'Tiguan', 'year_from' => 2016, 'year_to' => 2020]);
        ConfiguratorProduct::create(['handle' => 'wrong-fields', 'title' => 'Radio Volkswagen Tiguan 2019',
            'category' => 'screen', 'brand' => 'FORD', 'model' => 'Focus', 'year_from' => 2016, 'year_to' => 2020]);
        ConfiguratorProduct::create(['handle' => 'wrong-year', 'title' => 'Radio Volkswagen Tiguan 2019',
            'category' => 'screen', 'brand' => 'VOLKSWAGEN', 'model' => 'Tiguan', 'year_from' => 2007, 'year_to' => 2015]);
        $search = app(WhatsAppCatalogSearch::class);
        $r = $search->search(['Tengo un Volkswagen Tiguan del 2019']);
        $this->assertSame(1, $r['matching_specific_screens']);
        $this->assertSame(['correct-fields'], array_column($r['products'], 'handle'));
        $p->update(['title' => 'Radio Volkswagen Tiguan Special Edition 2018-2025']);
        $next = $search->search(['Tengo un Volkswagen Tiguan del 2019']);
        $this->assertSame($r['vehicle'], $next['vehicle']);
        $this->assertSame(array_column($r['products'], 'handle'), array_column($next['products'], 'handle'));
        $r = $search->search(['Tengo un Volkswagen Tiguan Special Edition del 2023']);
        $this->assertSame(0, $r['matching_specific_screens']);
        $this->assertSame('Tiguan', $r['vehicle']['model']);
    }

    public function test_named_multibrand_screen_recommends_cheapest_eight_core_for_customer_vehicle(): void
    {
        $title = 'Autoradio Android 15 5G Premium con CarPlay integrado para Citroën Berlingo/Peugeot Rifter/Peugeot Partner 2019-2024 y Opel Combo 2020-2024';
        $p = ConfiguratorProduct::create(['handle' => 'combo-premium', 'category' => 'screen', 'title' => $title,
            'brand' => 'OPEL', 'model' => 'COMBO', 'year_from' => 2020, 'year_to' => 2024]);
        $p->variants()->create(['title' => '4core 2G 32G', 'price' => 200, 'shopify_variant_id' => 'small']);
        $p->variants()->create(['title' => '8core 4G 64G', 'price' => 410, 'shopify_variant_id' => 'recommended']);
        $p->variants()->create(['title' => '8core 8G 128G', 'price' => 500, 'shopify_variant_id' => 'large']);
        ConfiguratorProduct::create(['handle' => 'rifter', 'category' => 'screen', 'title' => 'Radio Peugeot Rifter',
            'brand' => 'PEUGEOT', 'model' => 'RIFTER', 'year_from' => 2019, 'year_to' => 2024]);
        app(WhatsAppAssistantSettings::class)->save(['language' => 'auto']);
        $r = app(WhatsAppTestBot::class)->answer('estamos interesados en adquirir una pantalla de radio para un Opel Combo XL carga incrementada Express 1.5 TD del año 2022 a través de su página WEB, pero no tenemos claro cuál de las opciones ofertadas sería la mejor. '.$title);
        $this->assertSame(['brand' => 'OPEL', 'model' => 'COMBO', 'year' => 2022], $r['context']['vehicle']);
        $this->assertSame('standard', $r['mode']);
        $this->assertStringContainsString('8core 4G 64G', $r['text']);
        $this->assertStringContainsString('410,00 €', $r['text']);
        $this->assertStringContainsString('?variant=recommended', $r['text']);
        $this->assertStringContainsString('recomendamos', $r['text']);
        $this->assertNotNull($r['quote_state']['variant_id']);
        foreach (['it' => 'Quale variante è migliore?', 'en' => 'Which version is best?'] as $locale => $question) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $r = app(WhatsAppTestBot::class)->answer($question.' '.$title, [], ['brand' => 'OPEL', 'model' => 'COMBO', 'year' => 2022]);
            $this->assertSame('standard', $r['mode']);
            $this->assertStringContainsString('8core 4G 64G', $r['text']);
        }
        app(WhatsAppAssistantSettings::class)->save(['language' => 'it']);
        foreach (['quale e la migliore', 'qual è la migliore?'] as $question) {
            $r = app(WhatsAppTestBot::class)->answer($question, [], ['brand' => 'OPEL', 'model' => 'COMBO', 'year' => 2022], null, ['product' => $p->handle]);
            $this->assertSame('standard', $r['mode']);
            $this->assertStringContainsString('8core 4G 64G', $r['text']);
        }
        Http::assertNothingSent();
    }

    public function test_customer_taking_time_gets_courteous_reply_without_ai_or_new_sales_details(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $vehicle = ['brand' => 'OPEL', 'model' => 'COMBO', 'year' => 2022];
        foreach (['es' => 'Hola! De momento sigo pensando la mejor opción, no tengo prisa para decidirme Gracias',
            'it' => 'Ciao, ci sto pensando, non ho fretta di decidere. Grazie',
            'en' => 'Hello, I am still thinking about the best option, no rush. Thanks'] as $locale => $text) {
            $settings->save(['language' => 'auto', 'fallback_language' => 'es']);
            $r = app(WhatsAppTestBot::class)->conversationAnswer($text, [], $vehicle, hash('sha256', json_encode($vehicle)), false,
                [], '', [], ['product' => 'chosen-radio', 'installation_zone' => 'gran-canaria']);
            $this->assertSame('standard_general', $r['mode']);
            $this->assertStringContainsString(['it' => 'prenditi', 'es' => 'tómate', 'en' => 'take all'][$locale], $r['text']);
            $this->assertSame('chosen-radio', $r['quote_state']['product']);
            $this->assertSame($vehicle, $r['context']['vehicle']);
            $this->assertArrayNotHasKey('alert', $r);
        }
        $replies = app(\App\Services\WhatsAppStandardReplies::class);
        $this->assertFalse($replies->considering('Sigo pensando. ¿Cuánto cuesta con instalación?'));
        $this->assertFalse($replies->considering('Ci penso, vorrei un operatore'));
        Http::assertNothingSent();
    }

    public function test_ordinal_variant_advice_uses_displayed_radio_order_and_cheapest_eight_core_without_ai(): void
    {
        $a = ConfiguratorProduct::create(['handle' => 'qashqai-a', 'title' => 'Radio A Nissan Qashqai', 'category' => 'screen',
            'brand' => 'NISSAN', 'model' => 'Qashqai', 'year_from' => 2006, 'year_to' => 2013]);
        $b = ConfiguratorProduct::create(['handle' => 'qashqai-b', 'title' => 'Radio B Nissan Qashqai', 'category' => 'screen',
            'brand' => 'NISSAN', 'model' => 'Qashqai', 'year_from' => 2006, 'year_to' => 2013]);
        foreach ([$a, $b] as $p) {
            $p->variants()->create(['title' => '4core 4G 64G', 'price' => 418.46, 'shopify_variant_id' => $p->handle.'-4']);
            foreach (['3G 32G' => 424.56, '4G 32G' => 450, '6G 64G' => 480, '8G 128G' => 520] as $ram => $price) {
                $p->variants()->create(['title' => '8core '.$ram.' SnapDragon 6115', 'price' => $price, 'shopify_variant_id' => $p->handle.'-'.$price]);
            }
        }
        $vehicle = ['brand' => 'NISSAN', 'model' => 'Qashqai', 'year' => 2011];
        $history = [['role' => 'assistant', 'content' => "• Radio B\nhttps://www.autoradiocanario.com/products/qashqai-b?variant=b\n• Radio A\nhttps://www.autoradiocanario.com/products/qashqai-a?variant=a"]];
        foreach (['es' => ['la primera que version nme conseja?', 'la segunda qué versión aconseja?', 'De la primera qué versión propones?', 'De la segunda qué versión sugieres?'],
            'it' => ['La prima quale variante mi consigli?', 'La seconda quale versione consigli?', 'La prima quale variante raccomandi?', 'La seconda quale variante proponi?', 'La prima quale versione suggerisci?'],
            'en' => ['Which version of the first do you recommend?', 'Which version of the second would you suggest?', 'Which version of the first would you propose?']] as $locale => $questions) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            foreach ($questions as $i => $question) {
                $r = app(WhatsAppTestBot::class)->conversationAnswer($question, $history, $vehicle, hash('sha256', json_encode($vehicle)));
                $this->assertSame('standard', $r['mode']);
                $this->assertStringContainsString('8core 3G 32G SnapDragon 6115', $r['text']);
                $this->assertStringContainsString('424,56 €', $r['text']);
                $this->assertSame($i % 2 === 0 ? $b->handle : $a->handle, $r['quote_state']['product']);
                $this->assertStringContainsString('/products/'.$r['quote_state']['product'].'?variant=', $r['text']);
            }
        }
        Http::assertNothingSent();
    }

    public function test_quote_uses_single_previously_offered_radio_and_requested_memory(): void
    {
        foreach (['sl-nine', 'sl-seven'] as $handle) {
            $p = ConfiguratorProduct::create(['handle' => $handle, 'title' => 'Radio Mercedes SL R230 '.$handle,
                'category' => 'screen', 'brand' => 'MERCEDES', 'model' => 'SL', 'year_from' => 2001, 'year_to' => 2011]);
            $p->variants()->create(['title' => '8core 4G 64G', 'price' => 490]);
            $p->variants()->create(['title' => '8core 8G 128G', 'price' => 590]);
        }
        $context = app(WhatsAppCatalogSearch::class)->search(['Mercedes SL R230 2004']);
        $service = app(\App\Services\WhatsAppQuote::class);
        $text = 'Me interesa la 8core/ 8G/ 128G. Cuál seria el precio con instalación?';
        $history = [['role' => 'assistant', 'content' => 'Pantalla 9″ https://www.autoradiocanario.com/products/sl-nine?variant=example']];
        $r = $service->respond($text, $history, $context, [], 'es');
        $this->assertSame('sl-nine', $r['quote_state']['product']);
        $this->assertSame(ConfiguratorProduct::where('handle', 'sl-nine')->first()->variants()->where('title', '8core 8G 128G')->value('id'), $r['quote_state']['variant_id']);
        $this->assertStringNotContainsString('¿Qué radio prefieres?', $r['text']);
        $history[0]['content'] .= ' https://www.autoradiocanario.com/products/sl-seven';
        $r = $service->respond($text, $history, $context, [], 'es');
        $this->assertSame('product', $r['quote_state']['field']);
        $this->assertCount(2, $r['quote_state']['options']);
    }

    public function test_technical_quote_includes_operator_notice_in_pdf_caption_and_alert(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'honda-crv', 'title' => 'Radio Honda CR-V 2007-2012',
            'category' => 'screen', 'brand' => 'HONDA', 'model' => 'CR-V', 'year_from' => 2007, 'year_to' => 2012]);
        $p->variants()->create(['title' => '8core 8G 128G', 'price' => 590]);
        app(WhatsAppAssistantSettings::class)->save(['language' => 'es']);
        $text = 'Tengo un Honda CR-V 2012. Quisiera presupuesto 8core 8G 128G. Confirmar compatibilidad con subwoofer original, CANBUS y sistema 360 con calibración.';
        $r = app(WhatsAppTestBot::class)->conversationAnswer($text, [], [], null, true);
        $this->assertSame('quote', $r['mode']);
        $this->assertStringContainsString('Un operador te contactará', $r['text']);
        $this->assertStringContainsString('Un operador te contactará', $r['document']['caption']);
        $this->assertStringNotContainsString('El presupuesto corresponde', $r['document']['caption']);
        $this->assertSame($text, $r['alert']['question']);
        $this->assertNotEmpty($r['alert']['unknown']);
        $history = [['role' => 'user', 'content' => $text], ['role' => 'assistant', 'content' => $r['document']['caption']]];
        $next = app(WhatsAppTestBot::class)->conversationAnswer('Buenos días, Soy de Las Palmas de Gran Canaria Honda CRV Luxury matriculado en el 2012', $history, $r['context']['vehicle'], $r['signature'], false, [], '', [], $r['quote_state']);
        $this->assertSame('standard', $next['mode']);
        $this->assertStringContainsString('Gracias por la confirmación', $next['text']);
        $this->assertStringContainsString('Un operador revisará', $next['text']);
        $this->assertArrayNotHasKey('document', $next);
    }

    public function test_quote_keeps_all_vehicle_radios_regardless_of_requested_inches(): void
    {
        foreach (['small' => 'Radio Nissan X-Trail 9"', 'large' => 'Radio Nissan X-Trail 12"', 'unknown' => 'Radio Nissan X-Trail Android'] as $handle => $title) {
            ConfiguratorProduct::create(['handle' => $handle, 'title' => $title, 'category' => 'screen',
                'brand' => 'NISSAN', 'model' => 'X-Trail', 'year_from' => 2013, 'year_to' => 2022]);
        }
        $context = app(WhatsAppCatalogSearch::class)->search(['Nissan X-Trail T32 2020']);
        $service = app(\App\Services\WhatsAppQuote::class);
        foreach (['it' => 'Preventivo schermo 10,1 pollici', 'es' => 'Presupuesto pantalla 10,1 pulgadas', 'en' => 'Quote for a 10.1 inch screen'] as $locale => $text) {
            $r = $service->respond($text, [], $context, [], $locale);
            $this->assertSame('quote_choice', $r['mode']);
            $this->assertEqualsCanonicalizing(['small', 'large', 'unknown'], $r['quote_state']['options']);
            foreach ($context['products'] as $p) $this->assertStringContainsString($p['title'], $r['text']);
            $r = $service->respond('Preventivo presupuesto quote', [['role' => 'user', 'content' => $text]], $context, [], $locale);
            $this->assertEqualsCanonicalizing(['small', 'large', 'unknown'], $r['quote_state']['options']);
        }
    }

    public function test_compound_quote_proposes_minimum_eight_core_link_and_escalates_unanswered_technical_questions(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'xtrail-complex', 'category' => 'screen',
            'title' => 'Pantalla Android Nissan X-Trail T32', 'brand' => 'NISSAN', 'model' => 'X-Trail', 'year_from' => 2013, 'year_to' => 2022]);
        $p->variants()->create(['title' => '4core 4G 64G', 'price' => 300, 'shopify_variant_id' => 'four']);
        $p->variants()->create(['title' => '8Core 3G 32G-A', 'price' => 378.20, 'shopify_variant_id' => 'eight-min']);
        $p->variants()->create(['title' => '8Core 8G 128G-A', 'price' => 513.62, 'shopify_variant_id' => 'eight-high']);
        foreach (['es' => 'Hola. Tenemos un Nissan X-Trail T32 del año 2020 y estamos buscando una pantalla Android QLED de 10,1", preferiblemente 6/8 GB de RAM y 128 GB, con CarPlay y Android Auto. La radio del coche ya fue modificada anteriormente y actualmente no tiene cámara trasera. Adjunto una foto para que puedan ver el equipo que lleva. ¿Podrían indicarme qué modelo es exactamente compatible y qué necesitaríamos para instalarlo? También nos interesaría saber si se le puede añadir una cámara trasera aprovechando el cambio de radio y cuánto costaría el equipo. Estamos en Lanzarote, así que agradecería que me confirmaran el envío a Canarias y el precio final.',
            'it' => 'Nissan X-Trail 2020. Vorrei un preventivo con installazione, impianto modificato. Quale radio è compatibile? Si può aggiungere una telecamera? Spedizione Lanzarote e prezzo finale.',
            'en' => 'Nissan X-Trail 2020. Quote for installation: modified radio. Which radio is compatible? Can you add a rear camera? Shipping to Lanzarote and final price.'] as $locale => $text) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $r = app(WhatsAppTestBot::class)->conversationAnswer($text, [], [], null, true, [], '34611509809');
            $this->assertSame('handoff', $r['mode']);
            $this->assertStringContainsString('8Core 3G 32G-A', $r['text']);
            $this->assertStringContainsString('378,20 €', $r['text']);
            $this->assertStringContainsString('/products/xtrail-complex?variant=eight-min', $r['text']);
            $this->assertStringContainsString(['it' => 'Verrai ricontattato il prima possibile', 'es' => 'Te contactaremos lo antes posible', 'en' => 'We will contact you as soon as possible'][$locale], $r['text']);
            $this->assertStringContainsString(['it' => 'spedizione gratuita', 'es' => 'envío gratuito', 'en' => 'free shipping'][$locale], $r['text']);
            $this->assertSame($text, $r['alert']['question']);
            $this->assertStringContainsString('retrocamera', $r['alert']['unknown'][0]);
            $this->assertSame('xtrail-complex', $r['quote_state']['product']);
            $this->assertArrayNotHasKey('document', $r);
            $this->assertArrayNotHasKey('photos', $r['alert']);
        }
        Http::assertNothingSent();
    }

    public function test_ai_introduction_and_operator_keyword_work_in_all_languages(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['language' => 'auto']);
        foreach (['it' => ['Ciao', 'OPERATORE', 'entro 24 ore'], 'es' => ['Hola', 'OPERADOR', 'plazo de 24 horas'],
            'en' => ['Hello', 'OPERATOR', 'within 24 hours']] as $locale => [$greeting, $keyword, $deadline]) {
            $r = app(WhatsAppTestBot::class)->conversationAnswer($greeting, [], [], null, true);
            $this->assertStringContainsString($settings->get()['welcome_messages'][$locale], $r['text']);
            $this->assertStringContainsString($keyword, $r['text']);
            $this->assertStringContainsString($deadline, $r['text']);
            $r = app(WhatsAppTestBot::class)->conversationAnswer($keyword, [], [], null, false, [], '34611509809');
            $this->assertSame('handoff', $r['mode']);
            $this->assertStringContainsString($deadline, $r['text']);
            $this->assertSame($keyword, $r['alert']['question']);
        }
        Http::assertNothingSent();
    }

    public function test_missing_wiring_question_goes_to_operator_before_installation_sales_reply(): void
    {
        ConfiguratorProduct::create(['handle' => 'clio-wiring', 'title' => 'Radio Renault Clio', 'category' => 'screen',
            'brand' => 'RENAULT', 'model' => 'Clio', 'year_from' => 2010, 'year_to' => 2020]);
        foreach (['es' => 'Hola, Estaba pensando en comprar vuestra radio y que me la instales vosotros, pero al clio que tengo le falta la conexión de datos de la pantalla al módulo, el cable. Con la instalación de vuestra pantalla resolvería este problema y la del cable de antena? Un saludo',
            'it' => 'Vorrei comprare una radio e farla installare. Alla mia Clio manca il cavo dati dello schermo. La nuova radio risolve il problema del cavo antenna?',
            'en' => 'I want to buy a radio and have you install it in my Clio. The data cable is missing. Would a new radio solve this and the antenna cable problem?'] as $locale => $text) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $r = app(WhatsAppTestBot::class)->conversationAnswer($text, [], [], null, true, [], '34611509809');
            $this->assertSame('handoff', $r['mode']);
            $this->assertStringContainsString(['it' => 'Non possiamo confermare', 'es' => 'No podemos confirmar', 'en' => 'We cannot confirm'][$locale], $r['text']);
            $this->assertStringContainsString(['it' => 'foto dei cavi', 'es' => 'foto de los cables', 'en' => 'photo of the affected cables'][$locale], $r['text']);
            $this->assertStringContainsString('24', $r['text']);
            $this->assertStringNotContainsString('50', $r['text']);
            $this->assertSame($text, $r['alert']['question']);
            $this->assertStringContainsString('cablaggio', $r['alert']['unknown'][0]);
        }
        $this->assertFalse(app(\App\Services\WhatsAppStandardReplies::class)->wiringProblem('¿Instaláis radios y cable de antena?'));
        Http::assertNothingSent();
    }

    public function test_quote_recognizes_partial_screen_or_module_name_and_keeps_telde_installation_price(): void
    {
        $zone = \App\Models\InstallationZone::create(['name' => 'Gran Canaria', 'active' => true]);
        $zone->services()->create(['name' => 'Pantalla', 'price' => 50]);
        $zone->postalCodes()->create(['postal_code_from' => '35200', 'postal_code_to' => '35200']);
        $module = ConfiguratorProduct::create(['handle' => 'audi-module', 'category' => 'screen',
            'title' => 'Módulo CarPlay y Android Auto para Audi A4 A5 Q5 MMI 3G 2009-2015', 'title_it' => 'Modulo CarPlay Audi A5', 'title_en' => 'CarPlay module Audi A5',
            'brand' => 'AUDI', 'model' => 'A5', 'year_from' => 2009, 'year_to' => 2015]);
        $screen = ConfiguratorProduct::create(['handle' => 'audi-screen', 'category' => 'screen',
            'title' => 'Pantalla Android 8,8” CarPlay y Android Auto para Audi A5 2009-2016', 'title_it' => 'Schermo Android 8,8” Audi A5', 'title_en' => 'Android screen 8.8” Audi A5',
            'brand' => 'AUDI', 'model' => 'A5', 'year_from' => 2009, 'year_to' => 2016]);
        foreach ([$module, $screen] as $p) $p->variants()->create(['title' => '8core 4G 64G', 'price' => 400, 'shopify_variant_id' => $p->handle]);
        $context = app(WhatsAppCatalogSearch::class)->search(['AUDI A5 año 2010']);
        $service = app(\App\Services\WhatsAppQuote::class);
        foreach (['es' => ['Pantalla Android 8,8', 'Módulo CarPlay'], 'it' => ['Schermo Android 8,8', 'Modulo CarPlay'],
            'en' => ['Android screen 8.8', 'CarPlay module']] as $locale => $answers) {
            $first = $service->respond('Cuanto sería el coste con la Instalación? Estoy el Telde CP 35200', [], $context, [], $locale);
            $this->assertSame('quote_choice', $first['mode']);
            $this->assertStringContainsString('Gran Canaria: 50,00 €', $first['text']);
            $this->assertStringContainsString(['it' => 'scrivi solo 1 o 2.', 'es' => 'escribe solo 1 o 2.', 'en' => 'write only 1 or 2.'][$locale], $first['text']);
            foreach ($answers as $i => $answer) {
                $r = $service->respond($answer, [], $context, $first['quote_state'], $locale);
                $this->assertSame('quote', $r['mode']);
                $this->assertSame($i === 0 ? $screen->handle : $module->handle, $r['quote_state']['product']);
                $this->assertSame(5000, $r['installation']['amount']);
                $this->assertStringContainsString('Gran Canaria: 50,00 €', $r['text']);
                $this->assertSame($zone->id, $r['quote_state']['installation_zone']);
            }
            $ambiguous = $service->respond('Audi A5', [], $context, $first['quote_state'], $locale);
            $this->assertSame('quote_choice', $ambiguous['mode']);
        }
        Http::assertNothingSent();
    }

    public function test_numbered_variant_menu_explains_valid_range_and_accepts_tenth_option(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'ten-choices', 'title' => 'Radio Audi A5', 'category' => 'screen',
            'brand' => 'AUDI', 'model' => 'A5', 'year_from' => 2009, 'year_to' => 2016]);
        foreach (range(1, 10) as $n) $p->variants()->create(['title' => 'Basic option '.$n, 'price' => 200, 'shopify_variant_id' => 'choice-'.$n]);
        $context = app(WhatsAppCatalogSearch::class)->search(['Audi A5 2010']);
        $service = app(\App\Services\WhatsAppQuote::class);
        foreach (['it' => 'scrivi solo un numero da 1 a 10.', 'es' => 'escribe solo un número del 1 al 10.', 'en' => 'write only a number from 1 to 10.'] as $locale => $instruction) {
            $first = $service->respond('Preventivo presupuesto quote', [], $context, [], $locale);
            $this->assertSame('quote_choice', $first['mode']);
            $this->assertStringContainsString($instruction, $first['text']);
            $r = $service->respond('10', [], $context, $first['quote_state'], $locale);
            $this->assertSame('quote', $r['mode']);
            $this->assertStringContainsString('Basic option 10', $r['text']);
        }
        Http::assertNothingSent();
    }

    public function test_customer_will_write_again_when_decided_gets_short_closing_without_ai(): void
    {
        foreach (['es' => 'Perfecto.. si me decido en comprarlo luego te vuelvo a escribir Muchas gracias',
            'it' => 'Perfetto, se decido di comprarla poi vi riscrivo. Grazie mille',
            'en' => 'Perfect, if I decide to buy it I will contact you again. Thank you'] as $locale => $text) {
            app(WhatsAppAssistantSettings::class)->save(['language' => 'auto']);
            $r = app(WhatsAppTestBot::class)->conversationAnswer($text,
                [['role' => 'assistant', 'content' => 'Pantalla — 50,00 €']],
                ['brand' => 'AUDI', 'model' => 'A5', 'year' => 2010], null, false, [], '', [], ['installation_zone' => 1]);
            $this->assertSame('standard_general', $r['mode']);
            $this->assertStringContainsString(['it' => 'prenditi', 'es' => 'tómate', 'en' => 'take all'][$locale], $r['text']);
            $this->assertSame(1, $r['quote_state']['installation_zone']);
            $this->assertArrayNotHasKey('alert', $r);
            $this->assertStringNotContainsString('Canarias', $r['text']);
        }
        $replies = app(\App\Services\WhatsAppStandardReplies::class);
        $this->assertFalse($replies->considering('Si me decido, ¿cuánto cuesta con instalación?'));
        $this->assertFalse($replies->considering('I will contact you again, but is it in stock?'));
        Http::assertNothingSent();
    }

    public function test_variant_advice_uses_single_radio_in_last_offer_even_if_catalog_has_more(): void
    {
        $a = ConfiguratorProduct::create(['handle' => 'shown-juke', 'title' => 'Radio Tesla Nissan Juke', 'category' => 'screen',
            'brand' => 'NISSAN', 'model' => 'Juke', 'year_from' => 2011, 'year_to' => 2019]);
        $a->variants()->create(['title' => '8core 4G-32G', 'price' => 506.30, 'shopify_variant_id' => 'shown-min']);
        ConfiguratorProduct::create(['handle' => 'other-juke', 'title' => 'Altra radio Nissan Juke', 'category' => 'screen',
            'brand' => 'NISSAN', 'model' => 'Juke', 'year_from' => 2011, 'year_to' => 2019]);
        $history = [['role' => 'assistant', 'content' => 'Per Nissan Juke: https://www.autoradiocanario.com/products/shown-juke?variant=shown-min']];
        foreach (['it' => 'Che variante consigliato?', 'es' => 'Qué versión aconsejas?', 'en' => 'Which version do you recommend?'] as $locale => $text) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $r = app(WhatsAppTestBot::class)->answer($text, $history, ['brand' => 'NISSAN', 'model' => 'Juke', 'year' => 2016]);
            $this->assertSame('standard', $r['mode']);
            $this->assertStringContainsString('8core 4G-32G', $r['text']);
            $this->assertStringContainsString('506,30 €', $r['text']);
            $this->assertStringContainsString('?variant=shown-min', $r['text']);
        }
        Http::assertNothingSent();
    }

    public function test_installation_remembers_province_and_does_not_treat_camera_question_as_location(): void
    {
        $service = app(\App\Services\WhatsAppInstallation::class);
        $vehicle = ['brand' => 'NISSAN', 'model' => 'Juke', 'year' => 2016];
        foreach (['it' => ['Nissan Juke 2016 provincia di Milano', 'Fate anche installazione?', 'Avete anche una camera per la mia auto?'],
            'es' => ['Nissan Juke 2016 provincia de Milano', 'Hacéis instalación?', 'Tenéis una cámara para mi coche?'],
            'en' => ['Nissan Juke 2016 province Milano', 'Do you install radios?', 'Do you have a camera for my car?']] as $locale => [$location, $question, $camera]) {
            $history = [['role' => 'user', 'content' => $location]];
            $r = $service->respond($question, $history, $vehicle, [], $locale);
            $this->assertSame('standard_general', $r['mode']);
            $this->assertStringContainsString('35200', $r['text']);
            $this->assertTrue($r['quote_state']['installation_pending']);
            $history[] = ['role' => 'assistant', 'content' => $r['text']];
            $this->assertNull($service->respond($camera, $history, $vehicle, $r['quote_state'], $locale));
            $next = $service->respond('24044', $history, $vehicle, $r['quote_state'], $locale);
            $this->assertSame('handoff', $next['mode']);
            $this->assertStringContainsString('24044', $next['handoff_reason']);
        }
        Http::assertNothingSent();
    }

    public function test_handoff_answers_standards_and_otherwise_confirms_waiting_without_ai_or_new_alerts(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['standard_replies' => [['title' => 'Spedizione', 'topic' => 'Spedizione', 'category' => 'general', 'enabled' => true,
            'answers' => ['it' => 'Spediamo anche in Italia.', 'es' => 'Enviamos también a Italia.', 'en' => 'We also ship to Italy.']]]]);
        Mail::shouldReceive('raw')->never();
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $control = app(\App\Services\WhatsAppChatControl::class);
        $contact = $control->contact(config('waha.test_number'));
        $control->pause($contact, 'technical_handoff');
        foreach (['it' => ['Spedite in Italia?', 'Vorrei un preventivo', 'già passata a un operatore'],
            'es' => ['¿Hacéis envíos a Italia?', 'Quiero un presupuesto', 'en manos de un operador'],
            'en' => ['Do you ship to Italy?', 'I want a quote', 'already been passed to an operator']] as $locale => [$standard, $other, $notice]) {
            $settings->save(['language' => $locale]);
            $this->assertTrue($bot->reply($this->message(['id' => $locale.'-standard', 'body' => $standard]), time() - 1));
            $this->assertTrue($bot->reply($this->message(['id' => $locale.'-other', 'body' => $other]), time() - 1));
            $this->assertFalse($bot->reply($this->message(['id' => $locale.'-other', 'body' => $other]), time() - 1));
            $contact->refresh();
            $this->assertTrue($contact->paused);
            $this->assertSame('technical_handoff', $contact->pause_reason);
            Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], $notice));
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
        foreach (['dashboard', 'operator_message', 'send_unverified'] as $reason) {
            $control->pause($contact, $reason);
            $this->assertFalse($bot->reply($this->message(['id' => $reason, 'body' => 'Do you ship to Italy?']), time() - 1));
        }
    }

    public function test_camera_purchase_after_radio_quote_searches_vehicle_camera_catalog_without_ai(): void
    {
        foreach (range(1, 9) as $n) ConfiguratorProduct::create(['handle' => 'juke-screen-'.$n, 'title' => 'Radio Juke '.$n,
            'category' => 'screen', 'brand' => 'NISSAN', 'model' => 'Juke', 'year_from' => 2011, 'year_to' => 2019]);
        $camera = ConfiguratorProduct::create(['handle' => 'juke-rear-camera', 'title' => 'Cámara trasera Nissan Juke',
            'title_it' => 'Telecamera posteriore Nissan Juke', 'title_en' => 'Rear camera Nissan Juke',
            'category' => 'camera', 'brand' => 'NISSAN', 'model' => 'Juke', 'year_from' => 2011, 'year_to' => 2019]);
        $camera->variants()->create(['title' => 'Camera', 'price' => 56.12, 'shopify_variant_id' => 'camera-variant']);
        ConfiguratorProduct::create(['handle' => 'other-camera', 'title' => 'Cámara trasera Nissan Qashqai',
            'category' => 'camera', 'brand' => 'NISSAN', 'model' => 'Qashqai', 'year_from' => 2011, 'year_to' => 2019]);
        $vehicle = ['brand' => 'NISSAN', 'model' => 'Juke', 'year' => 2016];
        $history = [['role' => 'assistant', 'content' => 'Ecco il preventivo della radio.']];
        foreach (['it' => 'Hai camera posteriore?', 'es' => '¿Tenéis una cámara trasera para mi coche?', 'en' => 'Do you have a rear camera for my car?'] as $locale => $text) {
            app(WhatsAppAssistantSettings::class)->save(['language' => $locale]);
            $r = app(WhatsAppTestBot::class)->conversationAnswer($text, $history, $vehicle, null, false, [], '', [], ['product' => 'juke-screen-1']);
            $this->assertSame('accessory_offer', $r['mode']);
            $this->assertStringContainsString('56,12 €', $r['text']);
            $this->assertStringContainsString('/products/juke-rear-camera?variant=camera-variant', $r['text']);
            $this->assertStringNotContainsString('/products/other-camera', $r['text']);
            $this->assertStringNotContainsString('/products/juke-screen-', $r['text']);
            $this->assertSame('juke-rear-camera', $r['quote_state']['accessory_handle']);
            $this->assertArrayNotHasKey('alert', $r);
        }
        $offer = app(\App\Services\WhatsAppProductOffer::class);
        $this->assertNull($offer->cameraOffer('La camera è inclusa con la radio?', 'it', $vehicle));
        $this->assertNull($offer->cameraOffer('Che risoluzione ha la camera?', 'it', $vehicle));
        Http::assertNothingSent();
    }

    public function test_delivery_time_followup_is_standard_without_ai_in_all_languages(): void
    {
        $vehicle = ['brand' => 'VOLKSWAGEN', 'model' => 'T6', 'year' => 2017];
        foreach (['es' => "Ah vale\nA principio de mes\nMe pongo en contacto con ustedes\nY la compro\nCuánto tarda en llegar?",
            'it' => 'Va bene, la compro a inizio mese. Quanto ci vuole per arrivare?',
            'en' => 'I will buy it next month. How long does it take to arrive?'] as $locale => $text) {
            app(WhatsAppAssistantSettings::class)->save(['language' => 'auto']);
            $r = app(WhatsAppTestBot::class)->conversationAnswer($text, [], $vehicle, hash('sha256', json_encode($vehicle)), false);
            $this->assertSame('standard_general', $r['mode']);
            $this->assertStringContainsString('10–15', $r['text']);
            $this->assertStringContainsString(['it' => 'giorni', 'es' => 'días', 'en' => 'days'][$locale], $r['text']);
            $this->assertArrayNotHasKey('alert', $r);
            $r = app(WhatsAppTestBot::class)->waitingAnswer($text, [], $vehicle);
            $this->assertSame('standard_general', $r['mode']);
            $this->assertStringContainsString('10–15', $r['text']);
        }
        $standard = app(\App\Services\WhatsAppStandardReplies::class);
        foreach (['Ya he pedido la radio, cuánto tarda en llegar?', 'Quando arriva il mio ordine? È in ritardo.', 'When will my order arrive?'] as $text) {
            $this->assertFalse($standard->deliveryQuestion($text));
        }
        Http::assertNothingSent();
    }

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
            '*/api/default/chats/*/messages*' => fn () => Http::response($this->polledMessages),
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
        Http::assertSentCount(4);
    }

    public function test_voice_transcript_enters_conversation_and_duplicate_is_not_transcribed(): void
    {
        ConfiguratorProduct::create(['handle' => 'voice-skoda', 'title' => 'Radio Skoda Octavia 2004-2013',
            'category' => 'screen', 'brand' => 'SKODA', 'model' => 'OCTAVIA', 'year_from' => 2004, 'year_to' => 2013]);
        $message = $this->message(['body' => '', 'hasMedia' => true, 'type' => 'ptt', 'media' => ['mimetype' => 'audio/ogg']]);
        $this->partialMock(\App\Services\WhatsAppVoice::class)->shouldReceive('receive')->once()
            ->andReturn('Hola, tengo un Skoda Octavia del 2007');
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($message, time() - 1));
        $this->assertFalse($bot->reply($message, time() - 1));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], '/products/voice-skoda'));
        $history = Cache::store('file')->get('waha-test-history:'.app(WhatsAppAssistantSettings::class)->suffix());
        $this->assertSame('Hola, tengo un Skoda Octavia del 2007', $history[0]['content']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_unclear_voice_requests_text_without_handoff_or_catalog_answer(): void
    {
        app(WhatsAppAssistantSettings::class)->save(['fallback_language' => 'es']);
        $this->partialMock(\App\Services\WhatsAppVoice::class)->shouldReceive('receive')->once()->andThrow(new RuntimeException('Unclear'));
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->message(['body' => '', 'hasMedia' => true, 'type' => 'ptt']), time() - 1));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], 'No he podido entender bien el audio'));
        $this->assertFalse(\App\Models\WhatsAppChat::first()->paused);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_recovery_keeps_text_and_voice_in_order(): void
    {
        ConfiguratorProduct::create(['handle' => 'recovered-skoda', 'title' => 'Radio Skoda Octavia 2004-2013',
            'category' => 'screen', 'brand' => 'SKODA', 'model' => 'OCTAVIA', 'year_from' => 2004, 'year_to' => 2013]);
        $this->partialMock(\App\Services\WhatsAppVoice::class)->shouldReceive('receive')->once()->andReturn('Es un Skoda Octavia del 2007');
        $this->polledMessages = [
            $this->message(['id' => 'text-before-voice', 'body' => 'Hola, quiero una radio Android', 'timestamp' => time() - 2]),
            $this->message(['id' => 'voice-after-text', 'body' => '', 'hasMedia' => true, 'type' => 'ptt', 'timestamp' => time() - 1]),
        ];
        $r = app(WhatsAppTestBot::class)->recoverUnanswered();
        $this->assertSame('replied', $r['state']);
        $history = Cache::store('file')->get('waha-test-history:'.app(WhatsAppAssistantSettings::class)->suffix());
        $this->assertSame("Hola, quiero una radio Android\nEs un Skoda Octavia del 2007", $history[0]['content']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], '/products/recovered-skoda'));
        $this->assertSame('sent', Cache::store('file')->get('waha-test-message:'.hash('sha256', config('waha.session').'34611509809@c.us'.'text-before-voice')));
    }

    public function test_voice_in_paused_chat_does_not_start_transcription(): void
    {
        $this->partialMock(\App\Services\WhatsAppVoice::class)->shouldNotReceive('receive');
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $control = app(\App\Services\WhatsAppChatControl::class);
        $control->pause($control->contact('34611509809'), 'handoff');
        $this->assertFalse($bot->reply($this->message(['body' => '', 'hasMedia' => true, 'type' => 'ptt']), time() - 1));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
    }

    public function test_specific_screen_is_offered_with_link_without_price_or_ai(): void
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
            && ! str_contains($r['text'], '€')
            && ! str_contains($r['text'], 'Prezzi di catalogo indicativi')
            && str_contains($r['text'], '/products/audi-a4-radio?variant=12345')
            && ! str_contains(str_replace('?variant=12345', '', $r['text']), '?'));
        $this->assertFalse($bot->reply($this->message(['body' => 'Ciao ho Audi A4 2001, avete una radio?']), time() - 1));
        Http::assertSentCount(4);
    }

    public function test_initial_offer_omits_price_and_keeps_regional_variant_link(): void
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
        $this->assertStringNotContainsString('€', $offer);
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
        $this->assertTrue($bot->reply($this->message(['id' => 'follow-up']), time() - 1));
        app(WhatsAppAssistantSettings::class)->reset();
        $this->assertTrue($bot->reply($this->message(['id' => 'after-reset']), time() - 1));
        $control = app(\App\Services\WhatsAppChatControl::class);
        $control->resume($control->contact(config('waha.test_number')));
        $this->travel(1)->seconds();
        $this->assertTrue($bot->reply($this->message(['id' => 'after-resume', 'timestamp' => now()->timestamp]), time() - 1));
        $this->travelBack();
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
        $this->assertSame(app(\App\Services\WhatsAppWelcome::class)->initialMessage('es'), $sent[0][0]['text']);
        $this->assertStringNotContainsString('Bienvenida', $sent[1][0]['text']);
        $this->assertStringNotContainsString('Bienvenida', $sent[2][0]['text']);
        $this->assertSame(app(\App\Services\WhatsAppWelcome::class)->initialMessage('en'), $sent[3][0]['text']);
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
        $this->assertStringStartsWith('Buongiorno! Sono l’assistente AI', $sent[0][0]['text']);
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
            'Avete una radio per Mitsubishi Outlander?' => 'Di che anno è la tua auto? Scrivi l’anno con 4 cifre, ad esempio 2016.',
            'Busco una radio para Mitsubishi Outlander' => '¿De qué año es tu coche? Escribe el año con 4 cifras, por ejemplo 2016.',
            'Looking for a radio for Mitsubishi Outlander' => 'What year is your car? Write the 4-digit year, for example 2016.',
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
        $this->assertStringNotContainsString('€', $result['text']);
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

    public function test_compound_model_in_screen_fields_beats_camera_model_and_does_not_report_missing(): void
    {
        ConfiguratorProduct::create([
            'handle' => 'c4-cactus-smeg', 'category' => 'screen',
            'title' => 'Adaptador CarPlay para Citroën C4 Cactus 2014-2018 con Sistema SMEG/MRN',
            'brand' => 'PEUGEOT | CITROEN', 'model' => '1:208 | 2:C4 | 2:C3 | 2:C4 Cactus',
            'year_from' => 2014, 'year_to' => 2018, 'price_min' => 350,
        ]);
        ConfiguratorProduct::create([
            'handle' => 'camera-cactus', 'category' => 'camera', 'title' => 'Camera Citroen Cactus',
            'brand' => 'CITROEN', 'model' => 'C3 | C4 | Cactus', 'year_from' => 2006, 'year_to' => 2026,
        ]);
        $bot = app(WhatsAppTestBot::class);
        $r = $bot->conversationAnswer('Hola, tengo un Citroën C4 Cactus de 2014–2017 con sistema SMEG 5.4.B.R8, fecha 01-12-2015. Quiero CarPlay inalámbrico. ¿Qué referencia compatible y precio tiene?');
        $this->assertSame('offer', $r['mode']);
        $this->assertSame('C4 Cactus', $r['context']['vehicle']['model']);
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
        $this->assertTrue($bot->reply($this->message(['id' => 'after-alert', 'body' => 'AUX?']), time() - 1));
        $sent = Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/sendText'))->values();
        $this->assertStringNotContainsString('€', $sent[0][0]['text']);
        $this->assertStringContainsString('CarPlay wireless confermato.', $sent[1][0]['text']);
        $this->assertStringNotContainsString('?', $sent[1][0]['text']);
        $this->assertStringNotContainsString('/products/', $sent[1][0]['text']);
        $this->assertStringContainsString('Verrai ricontattato', $sent[2][0]['text']);
        $this->assertNotSame($sent[1][0]['text'], $sent[2][0]['text']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'openai.com') && $r['text']['format']['strict'] === true);
    }

    public function test_failed_operator_email_does_not_promise_contact_but_keeps_operator_pause(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP unavailable'));
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->message(['body' => 'Vorrei un operatore']), time() - 1));
        $sent = Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/sendText'))->last();
        $this->assertStringContainsString('non riesco a inoltrare', $sent[0]['text']);
        $this->assertStringNotContainsString('ricontattato', $sent[0]['text']);
        $this->assertTrue(Cache::store('file')->has('waha-test-handoff:'.app(WhatsAppAssistantSettings::class)->suffix()));
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
        $this->polledMessages = &$messages;
        $bot = app(WhatsAppTestBot::class);
        $r = $bot->recoverUnanswered();
        $this->assertSame(['state' => 'replied', 'questions' => 2, 'sent' => 1], $r);
        $this->assertSame('answered', $bot->recoverUnanswered()['state']);
        $sent = Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('spediamo ovunque', $sent->first()[0]['text']);
        $messages[] = ['id' => 'later-reply', 'fromMe' => true, 'timestamp' => time()];
        $this->assertSame(['state' => 'paused', 'questions' => 0, 'sent' => 0], $bot->recoverUnanswered());
    }

    public function test_manual_operator_message_during_generation_cancels_pending_reply(): void
    {
        $this->introducedVehicle();
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->onAi = function () {
            $this->polledMessages = [['id' => 'operator-during-generation', 'fromMe' => true, 'timestamp' => time(), 'body' => 'Manual reply']];
        };
        $this->assertFalse($bot->reply($this->message(['body' => 'Domanda tecnica da verificare']), time() - 1));
        $chat = app(\App\Services\WhatsAppChatControl::class)->contact(config('waha.test_number'));
        $this->assertTrue($chat->paused);
        $this->assertSame('operator_message', $chat->pause_reason);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
    }

    public function test_own_bot_messages_do_not_pause_chat_and_dashboard_pause_resume_invalidates_generation(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $this->assertTrue($bot->reply($this->message(['id' => 'greeting-before-pause', 'body' => 'Ciao']), time() - 1));
        $this->polledMessages = [['id' => 'outgoing-id', 'fromMe' => true, 'timestamp' => time()]];
        $bot->messages();
        $control = app(\App\Services\WhatsAppChatControl::class);
        $chat = $control->contact(config('waha.test_number'));
        $this->assertFalse($chat->paused);
        $this->introducedVehicle();
        $this->onAi = function () use ($control, $chat) {
            $control->pause($chat, 'dashboard');
            $control->resume($chat);
        };
        $before = Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/sendText'))->count();
        $this->assertFalse($bot->reply($this->message(['id' => 'cancelled-generation', 'body' => 'Una domanda tecnica']), time() - 1));
        $this->assertSame($before, Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/sendText'))->count());
    }

    public function test_persistent_pause_blocks_one_contact_and_does_not_replay_messages_from_pause(): void
    {
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        $control = app(\App\Services\WhatsAppChatControl::class);
        $chat = $control->contact(config('waha.test_number'));
        $control->pause($chat, 'dashboard');
        $this->assertFalse($bot->reply($this->message(['body' => 'Nuova radio universale']), time() - 1));
        $this->assertFalse($bot->reply($this->message(['body' => 'Spedite?']), time() - 1));
        $control->resume($chat);
        $this->assertFalse($bot->reply($this->message(['id' => 'old-paused-message', 'timestamp' => time() - 20]), 0));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/sendText'));
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
        foreach (['Avete autoradio universali 2DIN CarPlay? 100–500 euro', '¿Tenéis radios universales 2DIN con CarPlay? 100–500 euro', 'Do you have universal 2DIN CarPlay radios? 100–500 euro'] as $text) {
            $r = $bot->answer($text, [], ['brand' => 'AUDI', 'model' => 'A1', 'year' => 2012]);
            $this->assertSame('offer', $r['mode']);
            $this->assertSame(1, $r['context']['matching_universal_screens']);
            $this->assertStringContainsString('/products/universal-2din', $r['text']);
            $this->assertStringNotContainsString('/products/specific-audi', $r['text']);
            $this->assertSame('universal', $r['quote_state']['catalog_mode']);
        }
        $r = $bot->answer('Autoradio universale 2DIN CarPlay 100–500 euro');
        $this->assertSame('offer', $r['mode']);
        $this->assertSame([], $r['context']['vehicle']);
        $quote = $bot->conversationAnswer('Preventivo', [['role' => 'user', 'content' => 'Autoradio universale 2DIN CarPlay 100–500 euro']], [], $r['signature'], false, [], '', [], $r['quote_state']);
        $this->assertSame('quote', $quote['mode']);
        $this->assertNotNull($quote['document']);
        Http::assertNothingSent();
    }

    public function test_universal_request_asks_for_din_in_detected_language_before_offering_products(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['language' => 'auto', 'fallback_language' => 'es']);
        foreach (['1DIN', '2DIN'] as $din) {
            $p = ConfiguratorProduct::create(['handle' => 'din-choice-'.strtolower($din), 'category' => 'screen',
                'title' => 'Radio Universal '.$din, 'model' => 'Universal', 'meta' => ['din' => $din], 'price_min' => 100]);
            $p->variants()->create(['title' => 'Standard', 'option_value' => 'Standard', 'price' => 100, 'shopify_variant_id' => '100']);
        }
        $bot = app(WhatsAppTestBot::class);
        foreach ([
            'hai autoradio universali ? che fanno al caso mio?' => 'Cerchi una radio 1 DIN o 2 DIN? Scrivi 1 DIN oppure 2 DIN.',
            '¿Tenéis radios universales?' => '¿Buscas una radio 1 DIN o 2 DIN? Escribe 1 DIN o 2 DIN.',
            'Do you have universal radios?' => 'Are you looking for a 1 DIN or 2 DIN radio? Write 1 DIN or 2 DIN.',
        ] as $text => $question) {
            $r = $bot->answer($text);
            $this->assertSame('clarify', $r['mode']);
            $this->assertSame($question, $r['text']);
            $this->assertSame([], $r['context']['products']);
            $history = [['role' => 'user', 'content' => $text], ['role' => 'assistant', 'content' => $question]];
            $budget = $bot->answer('2 DIN', $history, [], null, $r['quote_state']);
            $this->assertSame('clarify', $budget['mode']);
            $this->assertStringContainsString('100–200', $budget['text']);
            $this->assertSame([], $budget['context']['products']);
            $this->assertSame('2DIN', $budget['quote_state']['universal_din']);
            $history[] = ['role' => 'user', 'content' => '2 DIN'];
            $history[] = ['role' => 'assistant', 'content' => $budget['text']];
            $invalid = $bot->answer('non so', $history, [], null, $budget['quote_state']);
            $this->assertSame('clarify', $invalid['mode']);
            $offer = $bot->answer('80–150', $history, [], null, $budget['quote_state']);
            $this->assertSame('offer', $offer['mode']);
            $this->assertEquals(['min' => 80, 'max' => 150], $offer['quote_state']['universal_budget']);
            $this->assertStringContainsString('/products/din-choice-2din', $offer['text']);
            $this->assertStringNotContainsString('/products/din-choice-1din', $offer['text']);
            $this->assertSame('2DIN', $offer['quote_state']['universal_din']);
            $saved = app(WhatsAppCatalogSearch::class)->universal(['Bluetooth'], [], $offer['quote_state']['universal_din']);
            $this->assertSame('2DIN', $saved['din']);
            $changed = app(WhatsAppCatalogSearch::class)->universal(['2DIN', '1DIN'], [], $saved['din']);
            $this->assertSame('1DIN', $changed['din']);
        }
        $this->assertSame('it', $settings->locale(['hai autoradio universali ? che fanno al caso mio?', '2 DIN']));
        $settings->save(['language' => 'en']);
        $this->assertSame('Are you looking for a 1 DIN or 2 DIN radio? Write 1 DIN or 2 DIN.', $bot->answer('hai autoradio universali?')['text']);
        Http::assertNothingSent();
    }

    public function test_universal_budget_filters_variants_and_parses_limits(): void
    {
        $search = app(WhatsAppCatalogSearch::class);
        foreach (['100-200', 'da 100 a 200 euro', 'entre 100 y 200', 'between 100 and 200'] as $text) {
            $this->assertEquals(['min' => 100, 'max' => 200], $search->budget($text, true));
        }
        $this->assertEquals(['min' => 0, 'max' => 200.50], $search->budget('massimo 200,50 €', true));
        $this->assertEquals(['min' => 0, 'max' => null], $search->budget('senza limiti', true));
        foreach (['un din', 'uno DIN', 'una din', 'one DIN', 'single-DIN'] as $text) {
            $this->assertSame('1DIN', $search->universal([$text])['din']);
            $this->assertNull($search->budget($text, true));
        }
        foreach (['due din', 'dos DIN', 'two DIN', 'double-DIN', 'doppio DIN'] as $text) {
            $this->assertSame('2DIN', $search->universal([$text])['din']);
            $this->assertNull($search->budget($text, true));
        }
        $this->assertNull($search->budget('2 DIN', true));
        $this->assertNull($search->budget('300-100', true));
        foreach (['cheap' => 50, 'fits' => 150, 'expensive' => 350] as $handle => $price) {
            $p = ConfiguratorProduct::create(['handle' => $handle, 'category' => 'screen', 'title' => 'Universal 2DIN',
                'model' => 'Universal', 'meta' => ['din' => '2DIN'], 'price_min' => $price]);
            $p->variants()->create(['title' => 'Standard', 'option_value' => 'Standard', 'price' => $price]);
        }
        $r = $search->universal(['2 DIN'], [], null, ['min' => 100, 'max' => 200]);
        $this->assertSame(['fits'], array_column($r['products'], 'handle'));
        Http::assertNothingSent();
    }

    public function test_universal_carplay_offer_uses_carplay_variant_instead_of_cheaper_no_carplay_version(): void
    {
        $p = ConfiguratorProduct::create(['handle' => 'universal-variant-test', 'category' => 'screen', 'title' => 'Radio MP5 Universal',
            'body_html' => 'Versioni No CarPlay e CarPlay.', 'model' => 'Universal', 'meta' => ['din' => '2DIN'], 'price_min' => 100]);
        $p->variants()->create(['title' => 'No CarPlay', 'option_value' => 'No CarPlay', 'price' => 100, 'shopify_variant_id' => '100']);
        $p->variants()->create(['title' => 'CarPlay', 'option_value' => 'CarPlay', 'price' => 200, 'shopify_variant_id' => '200']);
        $r = app(WhatsAppTestBot::class)->answer('Radio universale 2DIN con CarPlay 150–250 euro');
        $this->assertSame('offer', $r['mode']);
        $this->assertStringContainsString('200 €', $r['text']);
        $this->assertStringContainsString('?variant=200', $r['text']);
        $this->assertStringNotContainsString('?variant=100', $r['text']);
        Http::assertNothingSent();
    }

    public function test_unanswered_standard_question_is_answered_while_operator_handoff_remains(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['standard_replies' => [['title' => 'Formato DIN', 'topic' => 'Formato DIN', 'category' => 'general', 'enabled' => true,
            'answers' => ['it' => '2DIN indica il formato del corpo dell’autoradio, non i pollici dello schermo.']]]]);
        Cache::store('file')->put('waha-test-handoff:'.$settings->suffix(), true, now()->addHour());
        $this->polledMessages = [$this->message(['body' => '2DIN cosa significa?'])];
        $r = app(WhatsAppTestBot::class)->recoverUnanswered();
        $this->assertSame('replied', $r['state']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], '2DIN indica'));
        $this->assertTrue(Cache::store('file')->has('waha-test-handoff:'.$settings->suffix()));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_installation_standard_reply_cannot_clear_real_chat_handoff(): void
    {
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['language' => 'es', 'standard_replies' => [
            ['topic' => 'Installazione', 'category' => 'general', 'enabled' => true,
                'answers' => ['es' => 'Normalmente sí, cuesta 50 €. ¿Desde dónde nos escribes?']],
        ]]);
        $bot = app(WhatsAppTestBot::class);
        $bot->connect();
        Cache::store('file')->put('waha-test-handoff:'.$settings->suffix(), true, now()->addHour());
        $this->assertTrue($bot->reply($this->message(['body' => 'instala usted?']), time() - 1));
        $this->assertTrue(app(\App\Services\WhatsAppChatControl::class)->contact(config('waha.test_number'))->paused);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sendText') && str_contains($r['text'], '50 €'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com'));
    }

    public function test_unanswered_check_respects_chat_lock_and_uncertain_previous_send(): void
    {
        $this->polledMessages = [$this->message(['body' => 'Ciao'])];
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

    public function test_new_radio_request_requires_explicit_resume_after_handoff(): void
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
        $control = app(\App\Services\WhatsAppChatControl::class);
        $control->resume($control->contact(config('waha.test_number')));
        $this->travel(1)->seconds();
        $this->assertTrue($bot->reply($this->message(['id' => 'resumed-new-request', 'timestamp' => now()->timestamp, 'body' => 'Otra radio para Audi A4 2008']), time() - 1));
        $this->travelBack();
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
