<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WhatsAppAssistantSettings;
use App\Services\WhatsAppSessionConnection;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppSessionRestartTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private bool $failRestart = false;

    private string $sessionState = 'WORKING';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->dir = sys_get_temp_dir().'/autoradio-waha-restart-'.bin2hex(random_bytes(6));
        config([
            'cache.stores.file.path' => $this->dir, 'waha.key' => 'PRIVATE-WAHA-KEY',
            'waha.url' => 'http://127.0.0.1:3000', 'waha.session' => 'default',
            'waha.test_number' => '34611509809', 'waha.test_enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:3000/api/sessions/default/restart' => fn () => $this->failRestart
                ? Http::response(['message' => 'PRIVATE-WAHA-KEY internal error'], 500) : Http::response(['status' => 'STARTING']),
            'http://127.0.0.1:3000/api/sessions/default' => fn () => Http::response(['status' => $this->sessionState]),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_restart_is_administrator_only(): void
    {
        $this->post('/dashboard/whatsapp/restart')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post('/dashboard/whatsapp/restart')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_restart_only_affects_configured_session_and_preserves_settings_and_memory(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $settings = app(WhatsAppAssistantSettings::class);
        $settings->save(['enabled' => false]);
        $rules = $settings->get();
        $history = 'waha-test-history:'.$settings->suffix();
        Cache::store('file')->put($history, ['existing conversation'], now()->addMinute());
        $this->postJson('/dashboard/whatsapp/restart', ['session' => 'other-session'])->assertStatus(202)
            ->assertJsonPath('connection.status', 'STARTING')->assertDontSee('PRIVATE-WAHA-KEY');
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->url() === 'http://127.0.0.1:3000/api/sessions/default/restart'
            && $r->hasHeader('X-Api-Key', 'PRIVATE-WAHA-KEY'));
        Http::assertSentCount(1);
        $this->assertSame($rules, $settings->get());
        $this->assertSame(['existing conversation'], Cache::store('file')->get($history));
        $this->assertTrue(Cache::store('file')->has(app(WhatsAppSessionConnection::class)->revisionKey()));
    }

    public function test_repeated_restart_is_rejected_without_another_waha_request(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/dashboard/whatsapp/restart')->assertStatus(202);
        $this->postJson('/dashboard/whatsapp/restart')->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_status_reports_qr_required_without_leaking_credentials(): void
    {
        $this->sessionState = 'SCAN_QR_CODE';
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->getJson('/dashboard/whatsapp/status')->assertOk()
            ->assertJsonPath('connection.available', true)->assertJsonPath('connection.status', 'SCAN_QR_CODE')
            ->assertDontSee('PRIVATE-WAHA-KEY');
    }

    public function test_waha_error_is_sanitized_and_production_restart_is_disabled(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->failRestart = true;
        $this->postJson('/dashboard/whatsapp/restart')->assertStatus(503)->assertDontSee('PRIVATE-WAHA-KEY');
        Http::assertSentCount(1);
        $this->app['env'] = 'production';
        $this->postJson('/dashboard/whatsapp/restart')->assertStatus(422);
        Http::assertSentCount(1);
    }
}
