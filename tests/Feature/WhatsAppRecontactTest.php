<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WhatsAppChatControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppRecontactTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->dir = sys_get_temp_dir().'/waha-recontact-test-'.bin2hex(random_bytes(6));
        config(['cache.stores.file.path' => $this->dir]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        config(['waha.url' => 'http://127.0.0.1:3000', 'waha.key' => 'secret', 'waha.session' => 'default', 'services.openai.api_key' => 'ai-secret']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_draft_reads_every_page_in_order_without_sending_or_changing_chat_state(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        app(WhatsAppChatControl::class)->pause($chat, 'dashboard');
        $before = $chat->fresh()->toArray();
        $messages = collect(range(1, 105))->map(fn ($i) => ['id' => 'm'.$i, 'timestamp' => $i,
            'body' => 'BMW X1 Testo '.$i, 'fromMe' => $i % 2 === 0, 'hasMedia' => $i === 2])->reverse()->values();
        Http::fake([
            '*/lids/pn/*' => Http::response([], 404),
            'http://127.0.0.1:3000/api/default/chats/*/messages*' => fn ($r) => Http::response($messages->slice((int) $r['offset'], 100)->values()->all()),
            'https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
                ['content' => [['type' => 'output_text', 'text' => json_encode(['subject' => 'BMW X1', 'evidence' => 'BMW X1 Testo 1', 'message' => 'Hola, ¿seguimos con la radio para tu BMW X1?'])]]],
            ]]),
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact')->assertOk()
            ->assertJsonPath('phone', $chat->phone)->assertJsonPath('messages', 105)->assertJsonPath('attachments', 1)
            ->assertJsonPath('text', 'Hola, ¿seguimos con la radio para tu BMW X1?');
        Http::assertSent(function ($r) {
            if ($r->url() !== 'https://api.openai.com/v1/responses') return false;
            $input = json_decode($r['input'][0]['content'], true);

            return count($input) === 105 && $input[0]['text'] === 'BMW X1 Testo 1' && $input[104]['text'] === 'BMW X1 Testo 105'
                && $input[0]['sender'] === 'cliente' && $input[1]['sender'] === 'negozio' && $r['store'] === false;
        });
        Http::assertSentCount(5);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendText') || str_contains($r->url(), '/read'));
        $this->assertSame($before, $chat->fresh()->toArray());
    }

    public function test_draft_is_admin_only_local_and_scoped_to_session(): void
    {
        Http::fake();
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $url = '/dashboard/whatsapp/chats/'.$chat->id.'/recontact';
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $chat->update(['session' => 'other']);
        $this->postJson($url)->assertNotFound();
        $chat->update(['session' => 'default']);
        $this->app['env'] = 'production';
        $this->postJson($url)->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_history_failure_does_not_generate_a_partial_draft_or_expose_credentials(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        Http::fake(['http://127.0.0.1:3000/*' => Http::response(['error' => 'secret'], 500)]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact')->assertStatus(503)->assertDontSee('secret');
        Http::assertSentCount(1);
    }

    public function test_repeated_history_page_is_rejected_without_ai_generation(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        Http::fake(['http://127.0.0.1:3000/*' => Http::response([['id' => 'same', 'body' => 'Hola', 'timestamp' => 1]])]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact')->assertStatus(422);
        Http::assertSentCount(2);
    }

    public function test_lid_history_is_read_even_when_phone_history_contains_a_message(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        Http::fake([
            '*/lids/pn/*' => Http::response(['pn' => '34611111111@c.us', 'lid' => '123456789@lid']),
            '*/chats/*/messages*' => function ($r) {
                if ((int) $r['offset'] > 0) return Http::response([]);
                return Http::response(str_contains($r->url(), '%40lid')
                    ? [['id' => 'older', 'timestamp' => 1, 'fromMe' => false, 'body' => 'Cerco una radio per BMW X1'],
                        ['id' => 'last', 'timestamp' => 2, 'fromMe' => false, 'body' => 'Grazie']]
                    : [['id' => 'last', 'timestamp' => 2, 'fromMe' => false, 'body' => 'Grazie']]);
            },
            'https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
                ['content' => [['type' => 'output_text', 'text' => json_encode(['subject' => 'BMW X1',
                    'evidence' => 'Cerco una radio per BMW X1', 'message' => 'Ciao, riprendiamo la scelta della radio per BMW X1 qui su WhatsApp?'])]]],
            ]]),
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact')->assertOk()
            ->assertJsonPath('messages', 2)->assertJsonPath('subject', 'BMW X1');
        Http::assertSentCount(6);
    }

    public function test_generic_drafts_are_rejected_and_phone_proposals_are_adapted(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach ([['status' => 422, 'subject' => 'radio', 'evidence' => 'BMW X1', 'message' => 'Ancora interessato alla radio?'],
            ['status' => 200, 'subject' => 'BMW X1', 'evidence' => 'BMW X1', 'message' => 'Ti chiamiamo per la BMW X1?']] as $draft) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake([
                '*/lids/pn/*' => Http::response([], 404),
                '*/chats/*/messages*' => fn ($r) => Http::response((int) $r['offset'] === 0
                    ? [['id' => 'm1', 'timestamp' => 1, 'fromMe' => false, 'body' => 'Cerco radio BMW X1']] : []),
                'https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
                    ['content' => [['type' => 'output_text', 'text' => json_encode($draft)]]],
                ]]),
            ]);
            $this->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact')->assertStatus($draft['status']);
        }
    }

    public function test_business_only_history_shows_missing_customer_error_without_calling_ai(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        Http::fake([
            '*/lids/pn/*' => Http::response([], 404),
            '*/chats/*/messages*' => fn ($r) => Http::response((int) $r['offset'] === 0
                ? [['id' => 'outgoing', 'timestamp' => 1, 'fromMe' => true, 'body' => 'Buongiorno']] : []),
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact')->assertStatus(422)
            ->assertJsonPath('message', 'WAHA non restituisce i messaggi del cliente. Premi Recupera storico, poi riprova a preparare il ricontatto.');
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.openai.com'));
    }

    public function test_personalized_reordered_product_description_and_future_purchase_are_accepted(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $source = 'Pantalla de 9” con CarPlay inalámbrico para tu California T6 de 2017.';
        $message = '¡Hola! Sobre tu California T6 de 2017 y la pantalla de 9 pulgadas con CarPlay inalámbrico que vimos, comentaste que querías comprarla a principio de mes. ¿Te queda alguna duda que podamos aclarar por aquí?';
        Http::fake([
            '*/lids/pn/*' => Http::response([], 404),
            '*/chats/*/messages*' => fn ($r) => Http::response((int) $r['offset'] === 0 ? [
                ['id' => '1', 'timestamp' => 1, 'fromMe' => true, 'body' => $source],
                ['id' => '2', 'timestamp' => 2, 'fromMe' => false, 'body' => 'A principio de mes me pongo en contacto con ustedes y la compro'],
            ] : []),
            'https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
                ['content' => [['type' => 'output_text', 'text' => json_encode([
                    'subject' => 'California T6 2017, pantalla de 9 pulgadas con CarPlay inalámbrico',
                    'evidence' => 'Pantalla de 9 pulgadas con CarPlay inalámbrico para tu California T6 de 2017.', 'message' => $message,
                ])]]],
            ]]),
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact')->assertOk()->assertJsonPath('text', $message);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.openai.com') && str_contains($r['instructions'], 'NON significano che ha già acquistato'));
    }

    public function test_admin_can_rewrite_current_draft_using_italian_feedback_without_sending(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $control = app(WhatsAppChatControl::class);
        $control->pause($chat, 'dashboard');
        $before = $chat->fresh()->toArray();
        Http::fake([
            '*/lids/pn/*' => Http::response([], 404),
            '*/chats/*/messages*' => fn ($r) => Http::response((int) $r['offset'] === 0
                ? [['id' => '1', 'timestamp' => 1, 'fromMe' => false, 'body' => 'Busco CarPlay para Mazda CX-5']] : []),
            'https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
                ['content' => [['type' => 'output_text', 'text' => json_encode(['subject' => 'Mazda CX-5',
                    'evidence' => 'Busco CarPlay para Mazda CX-5', 'message' => '¡Hola! ¿Te queda alguna duda antes de comprar?'])]]],
            ]]),
        ]);
        $text = 'Hola, sobre tu Mazda CX-5, ¿quieres fotos? Texto modificado manualmente.';
        $instructions = 'Più breve, togli foto e modello auto. Chiedi se ha dubbi prima di acquistare.';
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact/rewrite', compact('text', 'instructions'))
            ->assertOk()->assertJsonPath('text', '¡Hola! ¿Te queda alguna duda antes de comprar?')->assertJsonPath('phone', $chat->phone);
        Http::assertSent(function ($r) use ($text, $instructions) {
            if (! str_contains($r->url(), 'api.openai.com')) return false;
            $revision = json_decode($r['input'][1]['content'], true);

            return $revision['bozza_corrente'] === $text && $revision['indicazioni_amministratore_in_italiano'] === $instructions
                && str_contains($r['instructions'], 'mantieni la lingua del cliente') && $r['store'] === false;
        });
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendText'));
        $this->assertSame($before, $chat->fresh()->toArray());
    }

    public function test_rewrite_requires_admin_current_session_and_nonempty_bounded_feedback(): void
    {
        Http::fake();
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $url = '/dashboard/whatsapp/chats/'.$chat->id.'/recontact/rewrite';
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson($url, ['text' => 'Bozza', 'instructions' => ''])->assertUnprocessable()->assertJsonValidationErrors('instructions');
        $this->postJson($url, ['text' => 'Bozza', 'instructions' => str_repeat('a', 2001)])->assertUnprocessable();
        $chat->update(['session' => 'other']);
        $this->postJson($url, ['text' => 'Bozza', 'instructions' => 'Accorcia'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_carplay_features_are_preserved_and_call_proposals_get_policy_without_errors(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $cases = [
            'Hola, sobre la pantalla MP5 para tu Volkswagen Up 2017 con CarPlay, llamadas, GPS y Waze, ¿te queda alguna duda sobre el presupuesto con instalación?' => true,
            'Con CarPlay puedes usar el GPS del teléfono y hacer llamadas en tu Volkswagen Up 2017.' => true,
            'CarPlay permette chiamate Bluetooth sulla Volkswagen Up 2017.' => true,
            'CarPlay supports phone calls on your Volkswagen Up 2017.' => true,
            '¿Quieres que te llamemos para la Volkswagen Up 2017?' => false,
            'Podemos llamarte para la Volkswagen Up 2017.' => false,
            'Ti chiamiamo per la Volkswagen Up 2017?' => false,
            'We can call you about the Volkswagen Up 2017.' => false,
            'Podemos organizar una llamada para la Volkswagen Up 2017.' => false,
            'Podemos llamarte para la Volkswagen Up 2017. El presupuesto incluye instalación.' => false,
        ];
        foreach ($cases as $message => $unchanged) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake([
                '*/lids/pn/*' => Http::response([], 404),
                '*/chats/*/messages*' => fn ($r) => Http::response((int) $r['offset'] === 0
                    ? [['id' => '1', 'timestamp' => 1, 'fromMe' => false, 'body' => 'Volkswagen Up 2017 con CarPlay para llamadas y Waze']] : []),
                'https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
                    ['content' => [['type' => 'output_text', 'text' => json_encode(['subject' => 'Volkswagen Up 2017',
                        'evidence' => 'Volkswagen Up 2017 con CarPlay', 'message' => $message])]]],
                ]]),
            ]);
            $response = $this->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact/rewrite', ['text' => 'Bozza', 'instructions' => 'Rendi il testo più naturale']);
            $response->assertOk();
            if ($unchanged) $response->assertJsonPath('text', $message);
            else {
                $this->assertStringContainsString('WhatsApp', $response->json('text'));
                $this->assertNotSame($message, $response->json('text'));
                if (str_contains($message, 'El presupuesto')) $this->assertStringContainsString('El presupuesto incluye instalación.', $response->json('text'));
            }
        }
    }

    public function test_memory_avoids_repeated_ai_and_revisions_use_short_context_then_new_messages_only(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $messages = [['id' => '1', 'timestamp' => 1, 'fromMe' => false, 'body' => 'Busco BMW X1 '.str_repeat('dettaglio ', 500)]];
        $inputs = [];
        Http::fake([
            '*/lids/pn/*' => Http::response([], 404),
            '*/chats/*/messages*' => function ($r) use (&$messages) { return Http::response((int) $r['offset'] === 0 ? $messages : []); },
            'https://api.openai.com/v1/responses' => function ($r) use (&$inputs) {
                $inputs[] = $r['input'];

                return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5], 'output' => [
                    ['content' => [['type' => 'output_text', 'text' => json_encode(['subject' => 'BMW X1', 'evidence' => 'Busco BMW X1',
                        'message' => 'Hola, ¿seguimos con tu BMW X1?', 'summary' => 'Interesado en una radio para BMW X1; compra pendiente.'])]]],
                ]]);
            },
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $url = '/dashboard/whatsapp/chats/'.$chat->id.'/recontact';
        $this->postJson($url)->assertOk()->assertJsonPath('source', 'ai');
        $this->postJson($url)->assertOk()->assertJsonPath('source', 'memory')->assertJsonPath('input_tokens', 0);
        $this->assertCount(1, $inputs);
        $this->postJson($url.'/rewrite', ['text' => 'Hola BMW X1', 'instructions' => 'Più cordiale'])->assertOk();
        $this->assertCount(2, $inputs);
        $this->assertLessThan(strlen($inputs[0][0]['content']), strlen($inputs[1][0]['content']));
        $messages[] = ['id' => '2', 'timestamp' => 2, 'fromMe' => false, 'body' => '¿Tiene CarPlay?'];
        $this->postJson($url)->assertOk()->assertJsonPath('messages', 2);
        $delta = json_decode($inputs[2][0]['content'], true);
        $this->assertSame('¿Tiene CarPlay?', $delta['new_messages'][0]['text']);
        $this->assertCount(1, $delta['new_messages']);
        $this->assertStringContainsString('BMW X1', $delta['previous_summary']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendText'));
    }

    public function test_simple_new_customer_recontact_uses_bot_without_ai_key(): void
    {
        config(['services.openai.api_key' => null]);
        $p = \App\Models\ConfiguratorProduct::create(['handle' => 'radio-up', 'category' => 'screen', 'title' => 'Pantalla CarPlay Volkswagen Up']);
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $chat->update(['import_label_name' => 'Nuovo cliente']);
        Http::fake([
            '*/lids/pn/*' => Http::response([], 404),
            '*/chats/*/messages*' => fn ($r) => Http::response((int) $r['offset'] === 0 ? [
                ['id' => '1', 'timestamp' => 1, 'fromMe' => false, 'body' => 'Busco una pantalla CarPlay para Volkswagen Up'],
                ['id' => '2', 'timestamp' => 2, 'fromMe' => true, 'body' => 'https://www.autoradiocanario.com/products/radio-up'],
            ] : []),
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $r = $this->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact')->assertOk()
            ->assertJsonPath('source', 'bot')->assertJsonPath('input_tokens', 0);
        $this->assertStringContainsString('Volkswagen Up', $r->json('text'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai'));
    }

    public function test_approved_lessons_are_scoped_removable_and_exact_answers_cost_no_ai(): void
    {
        $control = app(WhatsAppChatControl::class);
        $a = $control->contact('34611111111');
        $b = $control->contact('34622222222');
        Http::fake();
        $this->postJson('/dashboard/whatsapp/chats/'.$a->id.'/lessons', [])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $data = ['scope' => 'client', 'instruction' => 'Rispondi su WhatsApp senza proporre telefonate.', 'question' => '¿Podéis llamarme?',
            'text' => 'Te ayudamos por mensajes WhatsApp.', 'locale' => 'es'];
        $saved = $this->postJson('/dashboard/whatsapp/chats/'.$a->id.'/lessons', $data)->assertCreated();
        $lessons = app(\App\Services\WhatsAppLessons::class);
        $this->assertSame($data['text'], $lessons->answer($data['question'], 'es', $a->phone));
        $this->assertNull($lessons->answer($data['question'], 'es', $b->phone));
        $this->assertNull($lessons->answer($data['question'], 'en', $a->phone));
        app(\App\Services\WhatsAppAssistantSettings::class)->save(['language' => 'es', 'test_number' => $a->phone]);
        $r = app(\App\Services\WhatsAppTestBot::class)->answer($data['question']);
        $this->assertSame('standard_general', $r['mode']);
        $this->assertSame($data['text'], $r['text']);
        $this->postJson('/dashboard/whatsapp/chats/'.$a->id.'/lessons', array_replace($data, ['scope' => 'product', 'product' => 'unrelated']))->assertStatus(422);
        $id = $saved->json('lessons.0.id');
        $this->deleteJson('/dashboard/whatsapp/chats/'.$b->id.'/lessons/'.$id)->assertNotFound();
        $this->deleteJson('/dashboard/whatsapp/chats/'.$a->id.'/lessons/'.$id)->assertOk();
        $this->assertNull($lessons->answer($data['question'], 'es', $a->phone));
        Http::assertNothingSent();
    }

    public function test_manual_message_works_without_waha_and_uses_only_admin_text_and_requested_language(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $before = $chat->fresh()->toArray();
        $this->app['env'] = 'production';
        config(['waha.key' => null]);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
            ['content' => [['type' => 'output_text', 'text' => 'Bonjour, avez-vous encore des questions sur votre devis ?']]],
        ]])]);
        $data = ['text' => 'Buongiorno, hai ancora dubbi sul preventivo?', 'language' => 'Francese', 'instructions' => 'Informale e breve'];
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/recontact/manual', $data)->assertOk()
            ->assertJsonPath('manual', true)->assertJsonPath('language', 'Francese')->assertJsonPath('messages', 0)
            ->assertJsonPath('locale', 'fr')->assertJsonPath('text', 'Bonjour, avez-vous encore des questions sur votre devis ?');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/responses'
            && json_decode($r['input'][0]['content'], true) === $data && $r['store'] === false);
        Http::assertSentCount(1);
        $this->assertSame($before, $chat->fresh()->toArray());
    }

    public function test_manual_message_is_admin_only_session_scoped_and_validated(): void
    {
        Http::fake();
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $url = '/dashboard/whatsapp/chats/'.$chat->id.'/recontact/manual';
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson($url, ['text' => '', 'language' => ''])->assertUnprocessable()->assertJsonValidationErrors(['text', 'language']);
        $chat->update(['session' => 'other']);
        $this->postJson($url, ['text' => 'Ciao', 'language' => 'Spagnolo'])->assertNotFound();
        Http::assertNothingSent();
    }
}
