<?php

namespace Tests\Unit;

use App\Services\VisitorBotDetector;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisitorBotDetectorTest extends TestCase
{
    public function test_it_keeps_a_normal_browser_as_a_visitor(): void
    {
        $request = Request::create('/', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36',
            'HTTP_ACCEPT_LANGUAGE' => 'it-IT,it;q=0.9',
            'HTTP_SEC_FETCH_SITE' => 'none',
        ]);

        $this->assertSame([
            'is_bot' => false,
            'should_block' => false,
            'reason' => null,
        ], app(VisitorBotDetector::class)->analyze($request));
    }

    #[DataProvider('blockedBots')]
    public function test_it_blocks_high_confidence_bots(array $server, string $reason): void
    {
        $result = app(VisitorBotDetector::class)->analyze(Request::create('/', server: $server));

        $this->assertTrue($result['is_bot']);
        $this->assertTrue($result['should_block']);
        $this->assertSame($reason, $result['reason']);
    }

    public static function blockedBots(): array
    {
        return [
            'declared crawler' => [['HTTP_USER_AGENT' => 'AhrefsBot/7.0'], 'Agente automatico dichiarato'],
            'command line client' => [['HTTP_USER_AGENT' => 'curl/8.0'], 'Agente automatico dichiarato'],
            'obsolete android' => [[
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 2.2) AppleWebKit/533.1',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US',
            ], 'Browser obsoleto o simulato'],
        ];
    }

    public function test_it_separates_a_suspicious_request_without_blocking_it(): void
    {
        $result = app(VisitorBotDetector::class)->analyze(Request::create('/', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]));

        $this->assertTrue($result['is_bot']);
        $this->assertFalse($result['should_block']);
        $this->assertSame('Richiesta senza segnali browser', $result['reason']);
    }

    public function test_it_classifies_search_engines_without_blocking_them(): void
    {
        $result = app(VisitorBotDetector::class)->analyze(Request::create('/', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ]));

        $this->assertTrue($result['is_bot']);
        $this->assertFalse($result['should_block']);
        $this->assertSame('Motore di ricerca', $result['reason']);
    }
}
