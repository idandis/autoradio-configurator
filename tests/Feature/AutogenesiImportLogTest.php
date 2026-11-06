<?php

namespace Tests\Feature;

use App\Services\AutogenesiImportLog;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutogenesiImportLogTest extends TestCase
{
    public function test_new_attempt_clears_old_log_and_old_process_cannot_append(): void
    {
        Storage::fake('local');
        $log = app(AutogenesiImportLog::class);
        $first = $log->start('https://es.aliexpress.com/item/1.html');
        $log->write($first, 'errore_precedente');
        $second = $log->start('https://es.aliexpress.com/item/2.html');
        $log->write($first, 'processo_vecchio');
        $log->write($second, 'lettura_descrizione');
        $text = Storage::disk('local')->get('autogenesi/diagnostics/import.log');
        $this->assertStringNotContainsString('errore_precedente', $text);
        $this->assertStringNotContainsString('processo_vecchio', $text);
        $this->assertStringNotContainsString('/item/1.html', $text);
        $this->assertStringContainsString('/item/2.html', $text);
        $this->assertStringContainsString('lettura_descrizione', $text);
    }

    public function test_diagnostic_log_does_not_include_api_keys_or_source_query_tokens(): void
    {
        Storage::fake('local');
        $log = app(AutogenesiImportLog::class);
        $id = $log->start('https://es.aliexpress.com/item/1.html?token=private');
        $log->write($id, 'errore', ['message' => 'sk-exampleSecret Bearer exampleToken']);
        $text = Storage::disk('local')->get('autogenesi/diagnostics/import.log');
        $this->assertStringNotContainsString('exampleSecret', $text);
        $this->assertStringNotContainsString('exampleToken', $text);
        $this->assertStringNotContainsString('token=private', $text);
        $this->assertStringContainsString('[redacted]', $text);
    }
}
