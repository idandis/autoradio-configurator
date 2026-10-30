<?php

namespace Tests\Feature;

use App\Services\WhatsAppAssistantSettings;
use App\Services\WhatsAppHealthMonitor;
use App\Services\WhatsAppSessionConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class WhatsAppHealthMonitorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private string $connection = 'WORKING';
    private bool $mailFails = false;
    private int $mails = 0;
    private string $body = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->dir = sys_get_temp_dir().'/autoradio-health-'.bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->dir);
        File::ensureDirectoryExists(storage_path('app/private/waha'));
        config(['cache.stores.file.path' => $this->dir.'/cache', 'waha.monitor_enabled' => true, 'waha.test_enabled' => true,
            'waha.session' => 'default', 'mail.notification_to' => 'admin@example.test']);
        app(WhatsAppAssistantSettings::class)->save(['enabled' => true]);
        $this->mock(WhatsAppSessionConnection::class)->shouldReceive('status')->andReturnUsing(fn () => ['available' => $this->connection !== 'UNREACHABLE', 'status' => $this->connection]);
        Mail::shouldReceive('mailer')->with('smtp')->andReturnSelf();
        Mail::shouldReceive('raw')->andReturnUsing(function ($body, $callback) {
            $this->mails++;
            $this->body = $body;
            $m = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email);
            $callback($m);
            $this->assertSame('admin@example.test', $m->getSymfonyMessage()->getTo()[0]->getAddress());
            if ($this->mailFails) throw new RuntimeException('SMTP failure');

            return new \stdClass;
        });
        $this->heartbeat();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function heartbeat(string $state = 'active'): void
    {
        Cache::store('file')->forever('waha-test-runtime', ['state' => $state, 'at' => time(), 'expires_at' => time() + 7200]);
    }

    public function test_disconnect_is_debounced_and_only_one_email_is_sent_until_recovery(): void
    {
        $monitor = app(WhatsAppHealthMonitor::class);
        $this->assertSame('healthy', $monitor->check());
        $this->connection = 'SCAN_QR_CODE';
        $this->assertSame('pending', $monitor->check());
        $this->travel(1)->minutes();
        $this->assertSame('pending', $monitor->check());
        $this->travel(1)->minutes();
        $this->assertSame('notified', $monitor->check());
        $this->assertStringContainsString('SCAN_QR_CODE', $this->body);
        $this->assertStringContainsString('/dashboard/whatsapp', $this->body);
        $this->connection = 'FAILED';
        $this->assertSame('already_notified', $monitor->check());
        $this->assertSame(1, $this->mails);
        $this->connection = 'WORKING';
        $this->heartbeat();
        $this->assertSame('healthy', $monitor->check());
        $this->connection = 'FAILED';
        $this->assertSame('pending', $monitor->check());
        $this->travel(2)->minutes();
        $this->assertSame('notified', $monitor->check());
        $this->assertSame(2, $this->mails);
    }

    public function test_missing_bot_heartbeat_is_reported_even_when_whatsapp_is_connected(): void
    {
        Cache::store('file')->forget('waha-test-runtime');
        $monitor = app(WhatsAppHealthMonitor::class);
        $this->assertSame('pending', $monitor->check());
        $this->travel(2)->minutes();
        $this->assertSame('notified', $monitor->check());
        $this->assertStringContainsString('Bot non attivo', $this->body);
        $this->assertStringNotContainsString('Sessione WhatsApp scollegata', $this->body);
    }

    public function test_short_disconnect_does_not_send_email(): void
    {
        $monitor = app(WhatsAppHealthMonitor::class);
        $this->connection = 'FAILED';
        $this->assertSame('pending', $monitor->check());
        $this->travel(1)->minutes();
        $this->connection = 'WORKING';
        $this->heartbeat();
        $this->assertSame('healthy', $monitor->check());
        $this->assertSame(0, $this->mails);
    }

    public function test_manual_pause_or_disabled_rules_do_not_send_email(): void
    {
        $monitor = app(WhatsAppHealthMonitor::class);
        $this->connection = 'FAILED';
        app(WhatsAppAssistantSettings::class)->save(['enabled' => false]);
        $this->assertSame('paused', $monitor->check());
        app(WhatsAppAssistantSettings::class)->save(['enabled' => true]);
        file_put_contents(storage_path('app/private/waha/PAUSA'), 'stop');
        $this->assertSame('paused', $monitor->check());
        $this->assertSame(0, $this->mails);
    }

    public function test_smtp_failure_can_be_retried_without_losing_the_incident(): void
    {
        $monitor = app(WhatsAppHealthMonitor::class);
        $this->connection = 'UNREACHABLE';
        $monitor->check();
        $this->travel(2)->minutes();
        $this->mailFails = true;
        try {
            $monitor->check();
            $this->fail('Expected SMTP failure');
        } catch (RuntimeException $e) {
            $this->assertSame('SMTP failure', $e->getMessage());
        }
        $this->mailFails = false;
        $this->assertSame('notified', $monitor->check());
        $this->assertSame(2, $this->mails);
    }

    public function test_monitor_is_local_only_and_can_be_disabled(): void
    {
        $monitor = app(WhatsAppHealthMonitor::class);
        $this->app['env'] = 'production';
        $this->assertSame('disabled', $monitor->check());
        $this->app['env'] = 'local';
        config(['waha.monitor_enabled' => false]);
        $this->assertSame('disabled', $monitor->check());
        $this->assertSame(0, $this->mails);
    }
}
