<?php

namespace Tests\Feature;

use App\Services\WhatsAppVoice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class WhatsAppVoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['waha.url' => 'http://127.0.0.1:3000', 'waha.key' => 'test', 'waha.session' => 'default',
            'waha.voice_enabled' => true, 'waha.voice_python' => PHP_BINARY, 'waha.voice_model' => __FILE__]);
    }

    private function message(): array
    {
        return ['id' => 'voice-id', 'from' => '123@lid', 'fromMe' => false, 'hasMedia' => true,
            'body' => '', 'media' => ['mimetype' => 'audio/ogg; codecs=opus', 'url' => 'http://127.0.0.1:3000/api/files/default/voice.ogg']];
    }

    public function test_voice_is_verified_transcribed_locally_and_deleted(): void
    {
        $message = $this->message();
        Http::fake([
            '*/messages/voice-id*' => Http::response($message),
            '*/api/files/*' => function ($request, $options) {
                file_put_contents($options['sink'], 'audio fixture');

                return Http::response('', 200);
            },
        ]);
        Process::fake(['*' => Process::result(output: json_encode(['text' => 'Tengo un Skoda Octavia 2007', 'language' => 'es']))]);
        $this->assertSame('Tengo un Skoda Octavia 2007', app(WhatsAppVoice::class)->receive($message, '123@c.us'));
        $this->assertEmpty(Storage::disk('local')->allFiles('waha/voice'));
        Process::assertRan(fn ($p) => is_array($p->command) && $p->command[0] === PHP_BINARY
            && $p->command[1] === base_path('scripts/transcribe-whatsapp.py') && $p->command[3] === __FILE__);
        Http::assertSentCount(2);
    }

    public function test_untrusted_media_url_is_rejected_without_download_or_transcription(): void
    {
        $message = $this->message();
        $full = $message;
        $full['media']['url'] = 'https://example.com/api/files/voice.ogg';
        Http::fake(['*/messages/voice-id*' => Http::response($full)]);
        Process::fake();
        try {
            app(WhatsAppVoice::class)->receive($message, '123@c.us');
            $this->fail('Untrusted URL accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Percorso vocale WhatsApp non valido.', $e->getMessage());
        }
        Http::assertSentCount(1);
        Process::assertNothingRan();
    }

    public function test_unclear_audio_is_rejected_and_temporary_file_deleted(): void
    {
        Http::fake([
            '*/messages/voice-id*' => Http::response($this->message()),
            '*/api/files/*' => function ($request, $options) {
                file_put_contents($options['sink'], 'unclear audio');

                return Http::response('', 200);
            },
        ]);
        Process::fake(['*' => Process::result(output: '{"text":""}')]);
        try {
            app(WhatsAppVoice::class)->receive($this->message(), '123@c.us');
            $this->fail('Unclear audio accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Vocale non comprensibile.', $e->getMessage());
        }
        $this->assertEmpty(Storage::disk('local')->allFiles('waha/voice'));
    }

    public function test_download_limit_aborts_and_cleans_up(): void
    {
        Http::fake([
            '*/messages/voice-id*' => Http::response($this->message()),
            '*/api/files/*' => function ($request, $options) {
                file_put_contents($options['sink'], 'partial audio');
                $options['progress'](13 * 1024 * 1024, 0);

                return Http::response('', 200);
            },
        ]);
        Process::fake();
        try {
            app(WhatsAppVoice::class)->receive($this->message(), '123@c.us');
            $this->fail('Oversized audio accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Vocale troppo grande.', $e->getMessage());
        }
        $this->assertEmpty(Storage::disk('local')->allFiles('waha/voice'));
        Process::assertNothingRan();
    }

    public function test_wrong_sender_is_rejected_before_download(): void
    {
        $full = $this->message();
        $full['from'] = 'other@lid';
        Http::fake(['*/messages/voice-id*' => Http::response($full)]);
        Process::fake();
        try {
            app(WhatsAppVoice::class)->receive($this->message(), '123@c.us');
            $this->fail('Wrong sender accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Vocale WhatsApp non verificabile.', $e->getMessage());
        }
        Http::assertSentCount(1);
        Process::assertNothingRan();
    }

    public function test_uploaded_audio_is_deleted_after_transcription_failure(): void
    {
        $voice = $this->partialMock(WhatsAppVoice::class);
        $voice->shouldReceive('transcribe')->once()->andThrow(new RuntimeException('Unclear'));
        try {
            $voice->upload(\Illuminate\Http\UploadedFile::fake()->create('voice.ogg', 10, 'audio/ogg'));
            $this->fail('Expected transcription failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Unclear', $e->getMessage());
        }
        $this->assertEmpty(Storage::disk('local')->allFiles('waha/voice'));
    }
}
