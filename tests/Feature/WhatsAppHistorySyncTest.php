<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WhatsAppChatControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class WhatsAppHistorySyncTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->dir = sys_get_temp_dir().'/waha-history-'.bin2hex(random_bytes(6));
        config(['cache.stores.file.path' => $this->dir, 'waha.key' => 'secret', 'waha.url' => 'http://127.0.0.1:3000', 'waha.session' => 'default']);
        Http::preventStrayRequests();
        Http::fake([
            '*/api/sessions/default' => Http::response(['status' => 'WORKING', 'engine' => ['engine' => 'WEBJS']]),
            '*/lids/pn/*' => Http::response(['pn' => '34611111111@c.us', 'lid' => '123456789@lid']),
        ]);
        Process::fake(['*' => Process::result(output: json_encode(['requested' => true, 'before' => 1, 'after' => 17, 'limited' => false]))]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_admin_recovers_only_selected_chat_without_resuming_it_or_sending_messages(): void
    {
        $control = app(WhatsAppChatControl::class);
        $chat = $control->contact('34611111111');
        $control->pause($chat, 'dashboard');
        $before = $chat->fresh()->toArray();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/history')->assertOk()->assertJsonPath('after', 17)
            ->assertJsonPath('message', 'Caricati altri 16 messaggi. Ora puoi preparare il ricontatto.');
        Process::assertRan(fn ($p) => $p->command === [config('waha.docker_binary'), 'exec', '-i', config('waha.container'),
            'node', '-', 'default', '["123456789@lid","34611111111@c.us"]']);
        $this->assertSame($before, $chat->fresh()->toArray());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' || str_contains($r->url(), 'openai'));
    }

    public function test_recovery_is_admin_only_local_and_session_scoped(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $url = '/dashboard/whatsapp/chats/'.$chat->id.'/history';
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $chat->update(['session' => 'other']);
        $this->postJson($url)->assertNotFound();
        $chat->update(['session' => 'default']);
        $this->app['env'] = 'production';
        $this->postJson($url)->assertStatus(422);
        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_concurrent_recovery_is_rejected_and_docker_errors_are_sanitized(): void
    {
        $chat = app(WhatsAppChatControl::class)->contact('34611111111');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $lock = Cache::store('file')->lock('waha-history-sync:'.hash('sha256', 'default'), 90);
        $lock->get();
        $this->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/history')->assertStatus(422);
        Process::assertNothingRan();
        $lock->release();
        Process::fake(['*' => Process::result(errorOutput: 'private browser credentials', exitCode: 1)]);
        $this->postJson('/dashboard/whatsapp/chats/'.$chat->id.'/history')->assertStatus(503)->assertDontSee('private browser credentials');
    }
}
