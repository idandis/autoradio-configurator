<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsAppChat;
use App\Services\WhatsAppChatControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WhatsAppChatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_pause_one_chat_without_changing_other_contacts(): void
    {
        config(['waha.session' => 'default']);
        $control = app(WhatsAppChatControl::class);
        $a = $control->contact('34611111111');
        $b = $control->contact('34622222222');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->patchJson('/dashboard/whatsapp/chats/'.$a->id, ['paused' => true])
            ->assertOk()->assertJsonPath('paused', true)->assertJsonPath('pause_reason', 'dashboard');
        $this->assertFalse($b->fresh()->paused);
        $this->travel(2)->days();
        $this->assertTrue($a->fresh()->paused);
        $this->patchJson('/dashboard/whatsapp/chats/'.$a->id, ['paused' => false])
            ->assertOk()->assertJsonPath('paused', false)->assertJsonPath('pause_reason', null);
        $this->assertSame(2, $a->fresh()->revision);
        $this->assertNotNull($a->fresh()->resumed_at);
        $this->getJson('/dashboard/whatsapp/chats?q=611111111')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->travelBack();
    }

    public function test_chat_controls_are_admin_only_and_scoped_to_current_session(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $this->getJson('/dashboard/whatsapp/chats')->assertUnauthorized();
        $this->getJson('/dashboard/whatsapp/chats/labels')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->getJson('/dashboard/whatsapp/chats')->assertForbidden();
        $this->patchJson('/dashboard/whatsapp/chats/'.$chat->id, ['paused' => true])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->patchJson('/dashboard/whatsapp/chats/'.$chat->id, ['paused' => 'invalid'])->assertUnprocessable();
        $chat->update(['session' => 'another-session']);
        $this->patchJson('/dashboard/whatsapp/chats/'.$chat->id, ['paused' => true])->assertNotFound();
        $this->getJson('/dashboard/whatsapp/chats')->assertJsonCount(0, 'data');
    }

    public function test_manual_outgoing_message_pauses_but_bot_receipts_and_historical_messages_do_not(): void
    {
        $control = app(WhatsAppChatControl::class);
        $chat = $control->contact('34611111111');
        $control->observe($chat, [['id' => 'old', 'fromMe' => true, 'timestamp' => time() - 86400, 'body' => 'Never persist this']]);
        $this->assertFalse($chat->fresh()->paused);
        $control->receipt($chat, 'bot-message', 'bot');
        $control->observe($chat, [['id' => 'bot-message', 'fromMe' => true, 'timestamp' => time()]]);
        $this->assertFalse($chat->fresh()->paused);
        $control->observe($chat, [['id' => 'manual-message', 'fromMe' => true, 'timestamp' => time(), 'body' => 'Private text']]);
        $this->assertTrue($chat->fresh()->paused);
        $this->assertSame('operator_message', $chat->fresh()->pause_reason);
        $control->resume($chat);
        $control->observe($chat->fresh(), [['id' => 'manual-message', 'fromMe' => true, 'timestamp' => time()]]);
        $this->assertFalse($chat->fresh()->paused);
        $this->assertStringNotContainsString('Private text', json_encode(DB::table('whatsapp_chat_receipts')->get()));
        $this->assertStringNotContainsString('Never persist this', json_encode(WhatsAppChat::all()));
        $this->assertDatabaseCount('whatsapp_chats', 1);
    }

    public function test_sync_imports_contact_metadata_without_bodies_and_does_not_authorize_new_numbers(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->app['env'] = 'local';
        config(['waha.session' => 'default', 'waha.url' => 'http://127.0.0.1:3000', 'waha.key' => 'test-key', 'waha.test_number' => '34699999999']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['*/chats/overview*' => \Illuminate\Support\Facades\Http::response([
            ['id' => '34611111111@c.us', 'name' => 'Private name', 'lastMessage' => ['id' => 'historical', 'timestamp' => time() - 10, 'fromMe' => true, 'body' => 'Private body']],
            ['id' => '12345@g.us', 'lastMessage' => ['body' => 'Group text']],
        ])]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/dashboard/whatsapp/chats/sync', ['offset' => 0])->assertOk()->assertJsonPath('next_offset', null);
        $this->getJson('/dashboard/whatsapp/chats')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.phone', '34611111111')->assertJsonPath('data.0.authorized', false);
        $this->assertDatabaseCount('whatsapp_chats', 1);
        $this->assertFalse(WhatsAppChat::first()->paused);
        $saved = json_encode([WhatsAppChat::all(), DB::table('whatsapp_chat_receipts')->get()]);
        $this->assertStringNotContainsString('Private body', $saved);
        $this->assertStringNotContainsString('Private name', $saved);
        $this->postJson('/dashboard/whatsapp/chats/sync', ['offset' => -1])->assertUnprocessable();
        $this->app['env'] = 'production';
        $this->postJson('/dashboard/whatsapp/chats/sync', ['offset' => 0])->assertStatus(503);
    }

    public function test_label_import_only_reads_the_selected_label_preserves_pauses_and_has_no_extra_page(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->app['env'] = 'local';
        config(['waha.session' => 'default', 'waha.url' => 'http://127.0.0.1:3000', 'waha.key' => 'test-key']);
        $control = app(WhatsAppChatControl::class);
        $existing = $control->contact('34611111111');
        $control->pause($existing, 'dashboard');
        $rows = [
            ['id' => ['_serialized' => '34611111111@c.us'], 'body' => 'Never save message'],
            '34622222222@c.us', ['chatId' => '12345678@g.us'],
            ['id' => ['_serialized' => '90000001@lid']], '90000001@lid', '90000002@lid',
            ...array_fill(0, 100, ['id' => '34633333333@c.us']),
        ];
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake([
            '*/labels' => \Illuminate\Support\Facades\Http::response([['id' => 7, 'name' => 'NUOVO CLIENTE'], ['id' => 8, 'name' => 'ALTRO']]),
            '*/labels/7/chats' => \Illuminate\Support\Facades\Http::response($rows),
            '*/lids/90000001*' => \Illuminate\Support\Facades\Http::response(['lid' => '90000001@lid', 'pn' => '34644444444@c.us']),
            '*/lids/90000002*' => \Illuminate\Support\Facades\Http::response(['lid' => '90000002@lid', 'pn' => null]),
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->getJson('/dashboard/whatsapp/chats/labels')->assertOk()->assertJsonPath('labels.0.id', '7')->assertJsonPath('labels.0.name', 'NUOVO CLIENTE');
        $removed = $control->contact('34622222222');
        $removed->delete();
        $control->syncPage(0, '7', true);
        $this->assertDatabaseCount('whatsapp_chats', 2);
        $this->assertTrue($removed->fresh()->trashed());
        $this->assertSame('NUOVO CLIENTE', $existing->fresh()->import_label_name);

        $this->postJson('/dashboard/whatsapp/chats/sync', ['offset' => 0, 'label_id' => '7'])
            ->assertOk()->assertJsonPath('next_offset', null);
        $this->assertDatabaseCount('whatsapp_chats', 4);
        $this->assertDatabaseHas('whatsapp_chats', ['phone' => '34644444444', 'import_label_id' => '7', 'import_label_name' => 'NUOVO CLIENTE']);
        $this->getJson('/dashboard/whatsapp/chats')->assertOk()->assertJsonPath('data.0.import_label_name', 'NUOVO CLIENTE');
        $this->assertDatabaseHas('whatsapp_chats', ['phone' => '34644444444']);
        $this->assertDatabaseMissing('whatsapp_chats', ['phone' => '90000001']);
        $this->assertDatabaseMissing('whatsapp_chats', ['phone' => '90000002']);
        $this->assertTrue($existing->fresh()->paused);
        $this->assertSame('dashboard', $existing->fresh()->pause_reason);
        $this->assertStringNotContainsString('Never save message', json_encode(WhatsAppChat::all()));
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($r) => str_contains($r->url(), '/chats/overview') || str_contains($r->url(), '/labels/8/'));
        $this->postJson('/dashboard/whatsapp/chats/sync', ['offset' => 0, 'label_id' => '../all'])->assertUnprocessable();
    }

    public function test_label_read_failure_never_falls_back_to_importing_all_chats(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->app['env'] = 'local';
        config(['waha.session' => 'default', 'waha.url' => 'http://127.0.0.1:3000', 'waha.key' => 'test-key']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake([
            '*/labels' => \Illuminate\Support\Facades\Http::response([['id' => 'missing', 'name' => 'Removed']]),
            '*/labels/missing/chats' => \Illuminate\Support\Facades\Http::response([], 404),
        ]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/dashboard/whatsapp/chats/sync', ['offset' => 0, 'label_id' => 'missing'])->assertStatus(503);
        $this->assertDatabaseCount('whatsapp_chats', 0);
        \Illuminate\Support\Facades\Http::assertSentCount(2);
    }

    public function test_admin_deletes_one_row_without_deleting_other_chats_or_releasing_operator_pause(): void
    {
        $control = app(WhatsAppChatControl::class);
        $a = $control->contact('34611111111');
        $b = $control->contact('34622222222');
        $control->pause($a, 'dashboard');
        $control->receipt($a, 'own-outgoing', 'bot');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->deleteJson('/dashboard/whatsapp/chats/'.$a->id)->assertNoContent();
        $this->assertSoftDeleted('whatsapp_chats', ['id' => $a->id]);
        $this->getJson('/dashboard/whatsapp/chats')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
        $saved = $control->contact('34611111111');
        $this->assertTrue($saved->paused);
        $this->assertTrue($saved->trashed());
        $this->assertSame($a->id, $saved->id);
        $this->assertSame(2, $saved->revision);
        $this->assertDatabaseCount('whatsapp_chat_receipts', 1);
        $this->app['env'] = 'local';
        config(['waha.url' => 'http://127.0.0.1:3000', 'waha.key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake([
            '*/labels' => \Illuminate\Support\Facades\Http::response([['id' => '7', 'name' => 'NUOVO CLIENTE']]),
            '*/labels/7/chats' => \Illuminate\Support\Facades\Http::response(['34611111111@c.us']),
        ]);
        $control->syncPage(0, '7');
        $this->assertFalse($saved->fresh()->trashed());
        $this->assertTrue($saved->fresh()->paused);
    }

    public function test_delete_all_covers_other_pages_and_ignores_search_but_keeps_other_sessions(): void
    {
        $control = app(WhatsAppChatControl::class);
        for ($i = 0; $i < 30; $i++) $control->contact((string) (34610000000 + $i));
        $other = WhatsAppChat::create(['session' => 'another-session', 'phone' => '34699999999']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->deleteJson('/dashboard/whatsapp/chats?q=not-a-matching-number')->assertNoContent();
        $this->getJson('/dashboard/whatsapp/chats')->assertJsonCount(0, 'data');
        $this->assertSame(30, WhatsAppChat::onlyTrashed()->count());
        $this->assertFalse($other->fresh()->trashed());
        $this->assertSame(1, WhatsAppChat::count());
    }

    public function test_deletion_requires_admin_and_cannot_delete_another_sessions_row(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $this->deleteJson('/dashboard/whatsapp/chats')->assertUnauthorized();
        $this->deleteJson('/dashboard/whatsapp/chats/'.$chat->id)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->deleteJson('/dashboard/whatsapp/chats')->assertForbidden();
        $this->deleteJson('/dashboard/whatsapp/chats/'.$chat->id)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $chat->update(['session' => 'other']);
        $this->deleteJson('/dashboard/whatsapp/chats/'.$chat->id)->assertNotFound();
        $this->assertFalse($chat->fresh()->trashed());
    }

    public function test_legacy_handoff_is_persisted_and_only_explicit_resume_clears_it(): void
    {
        config(['waha.session' => 'default']);
        $suffix = hash('sha256', 'default34611111111@c.us');
        Cache::store('file')->put('waha-test-handoff:'.$suffix, true, now()->addHour());
        $control = app(WhatsAppChatControl::class);
        $chat = $control->contact('34611111111');
        $this->assertTrue($chat->paused);
        Cache::store('file')->forget('waha-test-handoff:'.$suffix);
        $this->assertTrue($control->contact('34611111111')->paused);
        $control->resume($chat);
        $this->assertFalse($control->contact('34611111111')->paused);
    }
}
