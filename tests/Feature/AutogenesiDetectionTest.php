<?php

namespace Tests\Feature;

use App\Models\AutogenesiProduct;
use App\Models\ConfiguratorProduct;
use App\Models\User;
use App\Services\AutogenesiDetection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutogenesiDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        \Illuminate\Support\Facades\Storage::fake('local');
        config(['services.openai.api_key' => 'test-key', 'cache.default' => 'array']);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Http::preventStrayRequests();
    }

    public function test_brand_research_exports_uncovered_year_ranges_and_hides_covered_models(): void
    {
        $cars = [
            ['brand' => 'Volkswagen', 'model' => 'Golf', 'year_from' => 1995, 'year_to' => 2003, 'market' => 'Spagna', 'source_url' => 'https://example.com/vw', 'evidence' => 'Golf 1995-2003 popular in Spain'],
            ['brand' => 'Volkswagen', 'model' => 'Polo', 'year_from' => 2010, 'year_to' => 2024, 'market' => 'Europa', 'source_url' => 'https://example.com/vw', 'evidence' => 'Polo 2010-2024 popular in Europe'],
            ['brand' => 'Volkswagen', 'model' => 'Passat', 'year_from' => 1973, 'year_to' => 2024, 'market' => 'Globale', 'source_url' => 'https://example.com/vw', 'evidence' => 'Passat 1973-2024'],
            ['brand' => 'Volkswagen', 'model' => 'Passat (B8)', 'year_from' => 2014, 'year_to' => 2024, 'market' => 'Europa', 'source_url' => 'https://example.com/vw', 'evidence' => 'Passat 2014-2024'],
            ['brand' => 'Ford', 'model' => 'Focus', 'year_from' => 2000, 'year_to' => 2005, 'market' => 'Spagna', 'source_url' => 'https://example.com/vw', 'evidence' => 'Focus 2000-2005'],
        ];
        Http::fakeSequence('api.openai.com/v1/responses')
            ->push(['status' => 'completed', 'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['sources' => [['url' => 'https://example.com/vw']]]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => implode("\n", array_column($cars, 'evidence'))]]],
            ]])
            ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['cars' => $cars, 'notes' => 'Documented ranges; not an exhaustive ranking.'])]]]]]);
        ConfiguratorProduct::create(['handle' => 'golf', 'category' => 'screen', 'title' => 'Unrelated', 'brand' => 'VW', 'model' => 'Golf', 'year_from' => 1998, 'year_to' => 2001]);
        ConfiguratorProduct::create(['handle' => 'polo', 'category' => 'screen', 'title' => 'Unrelated', 'brand' => 'VW', 'model' => 'Polo', 'year_from' => 2010, 'year_to' => 2024]);
        $this->from('/autogenesi')->post('/autogenesi/detect-missing', ['mode' => 'brand', 'brand' => 'VW'])->assertRedirect('/autogenesi')->assertSessionHasNoErrors();
        $r = session('autogenesi_detection');
        $this->assertSame(1995, $r['year_from']);
        $this->assertSame(2024, $r['year_to']);
        $this->assertCount(3, $r['cars']);
        $this->assertSame(['catalog' => 1, 'draft' => 0, 'missing' => 2], $r['counts']);
        $this->assertSame("VW Golf 1995 1997\nVW Golf 2002 2003\nVW Passat 1995 2024", $r['import_list']);
        Http::assertSent(fn ($r) => str_contains($r['instructions'] ?? '', 'NON-INTERACTIVE backend job')
            && str_contains($r['input'] ?? '', 'known_model_names_to_investigate'));
        Http::assertSentCount(2);
        app(AutogenesiDetection::class)->detectBrand('Volkswagen');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('autogenesi_batches', 0);
    }

    public function test_brand_accepts_separate_date_quotes_and_preserves_first_year_before_window(): void
    {
        $car = ['brand' => 'Toyota', 'model' => 'RAV4 (XA10)', 'year_from' => 1994, 'year_to' => 2000, 'market' => 'Europa',
            'source_url' => 'https://example.com/toyota', 'evidence' => 'RAV4 was launched in 1994 || This generation ended in 2000'];
        Http::fakeSequence('api.openai.com/v1/responses')
            ->push(['status' => 'completed', 'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['sources' => [['url' => 'https://example.com/toyota']]]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => "RAV4 was launched in 1994\nOther information.\nThis generation ended in 2000"]]],
            ]])
            ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['cars' => [$car], 'notes' => 'Copertura parziale.'])]]]]]);
        $this->post('/autogenesi/detect-missing', ['mode' => 'brand', 'brand' => 'Toyota'])->assertSessionHasNoErrors();
        $this->assertSame('Toyota RAV4 1995 2000', session('autogenesi_detection.import_list'));
        $this->assertSame('partial', session('autogenesi_detection.coverage'));
        $this->assertSame(1994, session('autogenesi_detection.cars.0.source_year_from'));
        $this->assertSame(0, session('autogenesi_detection.discarded_count'));
    }

    public function test_brand_comparison_checks_every_year_for_existing_model(): void
    {
        ConfiguratorProduct::create(['handle' => 'rav4', 'category' => 'screen', 'title' => 'Unrelated', 'brand' => 'Toyota', 'model' => 'RAV4', 'year_from' => 1998, 'year_to' => 2000]);
        $r = app(AutogenesiDetection::class)->compare(['mode' => 'brand', 'year' => null, 'cars' => [
            ['brand' => 'Toyota', 'model' => 'RAV4 (XA10)', 'year_from' => 1995, 'year_to' => 2000],
        ]]);
        $this->assertSame('Toyota RAV4 (XA10) 1995 1997', $r['import_list']);
        $this->assertSame('missing', $r['cars'][0]['status']);
    }

    public function test_camera_only_models_remain_missing_and_drafts_cover_only_their_years(): void
    {
        ConfiguratorProduct::create(['handle' => 'blade-camera', 'category' => 'camera', 'title' => 'Camera', 'brand' => 'Toyota', 'model' => 'Blade', 'year_from' => 2006, 'year_to' => 2012]);
        ConfiguratorProduct::create(['handle' => 'blade-dashcam', 'category' => 'screen', 'title' => 'Dashcam', 'brand' => 'Toyota', 'model' => 'Blade', 'year_from' => 2006, 'year_to' => 2012, 'meta' => ['type' => 'DASHCAM']]);
        AutogenesiProduct::create(['source_id' => 'prius', 'source_url' => 'https://example.com/product', 'status' => 'draft', 'handle' => 'prius-draft', 'title' => 'Radio', 'body_html' => '<p>Radio</p>',
            'brand' => 'Toyota', 'model' => 'Prius', 'year_from' => 2015, 'year_to' => 2020, 'images' => [], 'variants' => [], 'metadata' => []]);
        ConfiguratorProduct::create(['handle' => 'lc80', 'category' => 'screen', 'title' => 'Radio', 'brand' => 'Toyota', 'model' => 'Land Cruiser LC80', 'year_from' => 1990, 'year_to' => 1997]);
        $r = app(AutogenesiDetection::class)->compare(['mode' => 'brand', 'year_from' => 1995, 'year_to' => 2024, 'cars' => [
            ['brand' => 'Toyota', 'model' => 'Blade', 'year_from' => 2006, 'year_to' => 2012],
            ['brand' => 'Toyota', 'model' => 'Prius', 'year_from' => 1997, 'year_to' => 2024],
            ['brand' => 'Toyota', 'model' => 'Land Cruiser 80', 'year_from' => 1990, 'year_to' => 1997],
        ]]);
        $this->assertSame("Toyota Blade 2006 2012\nToyota Prius 1997 2014\nToyota Prius 2021 2024", $r['import_list']);
        $this->assertTrue($r['cars'][0]['camera_only']);
        $this->assertCount(3, $r['cars']);
        $this->assertSame(3, $r['checked_count']);
        $this->assertSame(['catalog' => 1, 'draft' => 0, 'missing' => 2], $r['counts']);
    }

    public function test_brand_does_not_request_radios_for_model_production_hiatus(): void
    {
        ConfiguratorProduct::create(['handle' => 'supra-camera', 'category' => 'camera', 'title' => 'Camera', 'brand' => 'Toyota', 'model' => 'Supra', 'year_from' => 1995, 'year_to' => 2024]);
        $r = app(AutogenesiDetection::class)->compare(['mode' => 'brand', 'year_from' => 1995, 'year_to' => 2024, 'cars' => [
            ['brand' => 'Toyota', 'model' => 'Supra', 'year_from' => 1978, 'year_to' => 2024, 'production_ranges' => [
                ['year_from' => 1978, 'year_to' => 2002], ['year_from' => 2019, 'year_to' => 2024],
            ]],
        ]]);
        $this->assertSame("Toyota Supra 1995 2002\nToyota Supra 2019 2024", $r['import_list']);
        $this->assertTrue($r['cars'][0]['camera_only']);
        $this->assertTrue($r['cars'][1]['camera_only']);
    }

    public function test_ongoing_production_is_valid_and_direct_model_source_is_preserved(): void
    {
        $evidence = 'Toyota / Prius / 1997 / present / https://example.com/prius';
        $r = app(AutogenesiDetection::class)->normalizeBrandResearch('Toyota', 1995, 2024, $evidence,
            ['https://example.com/general' => 'https://example.com/general'], ['cars' => [[
                'brand' => 'Toyota', 'model' => 'Prius', 'year_from' => 1997, 'year_to' => 2024,
                'market' => 'Globale', 'source_url' => 'https://example.com/general', 'evidence' => $evidence,
            ]], 'notes' => 'Copertura parziale.']);
        $this->assertCount(1, $r['cars']);
        $this->assertSame('https://example.com/prius', $r['cars'][0]['source_url']);
        $this->assertSame(2024, $r['cars'][0]['year_to']);
    }

    private function research(int $count = 50, bool $web = true, bool $firstFailure = false, bool $formatFailure = false, bool $headingOnly = false): void
    {
        $names = [['Volkswagen', 'Golf'], ['Peugeot', '2008'], ['Seat', 'Leon'], ['Skoda', 'Fabia'], ['Ford', 'Focus'], ['Opel', 'Corsa']];
        $cars = [];
        for ($i = 0; $i < $count; $i++) {
            [$brand, $model] = $names[$i] ?? ['Ford', 'Modello'.($i + 1)];
            $cars[] = ['brand' => $brand, 'model' => $model, 'year' => 2020, 'market' => 'Spagna', 'rank' => $i + 1,
                'units' => 100000 - $i * 100, 'full_year' => true, 'source_url' => 'https://example.com/annual-2020',
                'evidence' => $brand.' '.$model.($headingOnly ? ': ' : ' 2020: ').(100000 - $i * 100).' registrations, full calendar year.'];
        }
        $output = [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Annual registrations January-December 2020'."\n".implode("\n", array_column($cars, 'evidence'))]]]];
        if ($web) array_unshift($output, ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['sources' => [['url' => 'https://example.com/annual-2020']]]]);
        $sequence = Http::fakeSequence('api.openai.com/v1/responses');
        if ($firstFailure) $sequence->push(['error' => ['message' => 'Temporary server error']], 500);
        $sequence->push(['status' => 'completed', 'output' => $output]);
        if ($formatFailure) {
            $sequence->push(['error' => ['message' => 'Temporary server error']], 503);
            $sequence->push(['error' => ['message' => 'Temporary server error']], 503);
        }
        $sequence->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['cars' => $cars, 'notes' => 'Spanish annual rankings.'])]]]]]);
    }

    public function test_detection_researches_fifty_models_and_compares_vehicle_fields_years_and_drafts(): void
    {
        $this->research();
        ConfiguratorProduct::create(['handle' => 'multi', 'category' => 'screen', 'title' => 'Unrelated title', 'brand' => 'VW | Peugeot', 'model' => '1:Golf VII | 2:2008',
            'year_from' => 2000, 'year_to' => 2025, 'meta' => ['vehicle_compatibilities' => [
                ['brand' => 'VW', 'model' => 'Golf VII', 'year_from' => 2013, 'year_to' => 2019],
                ['brand' => 'Peugeot', 'model' => '2008', 'year_from' => 2013, 'year_to' => 2020],
            ]]]);
        ConfiguratorProduct::create(['handle' => 'leon', 'category' => 'screen', 'title' => 'Volkswagen Golf 2020', 'brand' => 'SEAT', 'model' => '1:LEÓN', 'year_from' => 2012, 'year_to' => 2020]);
        ConfiguratorProduct::create(['handle' => 'camera', 'category' => 'camera', 'title' => 'Ford Focus', 'brand' => 'Ford', 'model' => 'Focus', 'year_from' => 2020, 'year_to' => 2020]);
        AutogenesiProduct::create(['source_id' => '123', 'source_url' => 'https://example.com/product', 'status' => 'draft', 'handle' => 'fabia-draft', 'title' => 'Radio', 'body_html' => '<p>Radio</p>',
            'brand' => 'SKODA', 'model' => 'Fabia', 'year_from' => 2018, 'year_to' => 2020, 'images' => [], 'variants' => [], 'metadata' => []]);
        $this->from('/autogenesi')->post('/autogenesi/detect-missing', ['year' => 2020])->assertRedirect('/autogenesi')->assertSessionHasNoErrors();
        $result = session('autogenesi_detection');
        $this->assertCount(50, $result['cars']);
        $this->assertSame(['catalog' => 2, 'draft' => 1, 'missing' => 47], $result['counts']);
        $this->assertStringContainsString('Volkswagen Golf 2020', $result['import_list']);
        $this->assertStringContainsString('Ford Focus 2020', $result['import_list']);
        $this->assertStringNotContainsString('Peugeot 2008', $result['import_list']);
        $this->assertStringNotContainsString('Skoda Fabia', $result['import_list']);
        $this->assertDatabaseCount('autogenesi_batches', 0);
        $this->assertDatabaseCount('autogenesi_products', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => data_get($request->data(), 'tools.0.type') === 'web_search' && data_get($request->data(), 'tool_choice') === 'required');
        ConfiguratorProduct::create(['handle' => 'new', 'category' => 'screen', 'title' => 'Unrelated', 'brand' => 'Ford', 'model' => 'Focus', 'year_from' => 2020, 'year_to' => 2020]);
        $new = app(AutogenesiDetection::class)->detect(2020);
        $this->assertSame(46, $new['counts']['missing']);
        Http::assertSentCount(2);
    }

    public function test_annual_year_in_heading_does_not_discard_model_rows(): void
    {
        $this->research(32, true, false, false, true);
        $this->post('/autogenesi/detect-missing', ['year' => 2020])->assertSessionHasNoErrors();
        $this->assertCount(32, session('autogenesi_detection.cars'));
        $this->assertStringNotContainsString('2020', session('autogenesi_detection.cars.0.evidence'));
    }

    private function historicHtml(): string
    {
        return '<html><body><table><tr><td>Marca</td><td>Modelo</td><td>Unidades</td><td>Acumulado 2001</td></tr>'
            .'<tr><td>Citroen</td><td>XSARA</td><td>6.039</td><td>96.832</td></tr>'
            .'<tr><td>Renault</td><td>MEGANE</td><td>6.312</td><td>93.445</td></tr></table></body></html>';
    }

    public function test_historical_table_reads_annual_column_and_excludes_monthly_and_brand_tables(): void
    {
        $parser = app(\App\Services\AutogenesiHistoricalSales::class);
        $cars = $parser->parse($this->historicHtml());
        $this->assertCount(2, $cars);
        $this->assertSame(96832, $cars[0]['units']);
        $this->assertSame(93445, $cars[1]['units']);
        $this->assertSame([1, 2], array_column($cars, 'rank'));
        $this->assertSame([], $parser->parse(str_replace('Acumulado 2001', 'Diciembre 2001', $this->historicHtml())));
        $this->assertSame([], $parser->parse(str_replace('Modelo', 'Unidades', $this->historicHtml())));
    }

    public function test_2001_uses_direct_annual_source_when_ai_cannot_extract_rows(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => []]),
            \App\Services\AutogenesiHistoricalSales::SPAIN_2001 => Http::response($this->historicHtml()),
        ]);
        ConfiguratorProduct::create(['handle' => 'xsara', 'category' => 'screen', 'title' => 'Radio', 'brand' => 'Citroen', 'model' => 'XSARA', 'year_from' => 2000, 'year_to' => 2002]);
        $this->post('/autogenesi/detect-missing', ['year' => 2001])->assertSessionHasNoErrors();
        $this->assertCount(2, session('autogenesi_detection.cars'));
        $this->assertSame(['catalog' => 1, 'draft' => 0, 'missing' => 1], session('autogenesi_detection.counts'));
        $this->assertSame('Renault MEGANE 2001', session('autogenesi_detection.import_list'));
        $this->assertFalse(session('autogenesi_detection.complete'));
    }

    public function test_partial_research_compares_available_models_and_fills_list(): void
    {
        $this->research(32);
        $this->post('/autogenesi/detect-missing', ['year' => 2020])->assertSessionHasNoErrors();
        $result = session('autogenesi_detection');
        $this->assertCount(32, $result['cars']);
        $this->assertFalse($result['complete']);
        $this->assertSame(50, $result['target']);
        $this->assertSame(32, $result['counts']['missing']);
        $this->assertCount(32, explode("\n", $result['import_list']));
    }

    public function test_empty_research_does_not_overwrite_previous_result(): void
    {
        $this->research(0);
        $this->withSession(['autogenesi_detection' => ['year' => 2019]])->post('/autogenesi/detect-missing', ['year' => 2020])
            ->assertSessionHasErrors('year');
        $this->assertSame(['year' => 2019], session('autogenesi_detection'));
        $this->assertNull(Cache::get('autogenesi-detection-v2-2020'));
    }

    public function test_research_without_completed_web_search_is_rejected(): void
    {
        $this->research(50, false);
        $this->post('/autogenesi/detect-missing', ['year' => 2020])->assertSessionHasErrors('year');
        $this->assertNull(session('autogenesi_detection'));
        Http::assertSentCount(1);
    }

    public function test_detection_requires_admin_local_and_completed_calendar_year(): void
    {
        $this->post('/autogenesi/detect-missing', ['year' => now()->year])->assertSessionHasErrors('year');
        $this->actingAs(User::factory()->create(['is_admin' => false]))->post('/autogenesi/detect-missing', ['year' => 2020])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->app->detectEnvironment(fn () => 'production');
        $this->post('/autogenesi/detect-missing', ['year' => 2020])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_transient_openai_server_error_is_retried(): void
    {
        $this->research(32, true, true);
        $this->post('/autogenesi/detect-missing', ['year' => 2020])->assertSessionHasNoErrors();
        $this->assertCount(32, session('autogenesi_detection.cars'));
        Http::assertSentCount(3);
    }

    public function test_completed_web_research_survives_extraction_error_and_is_reused(): void
    {
        $this->research(32, true, false, true);
        $this->post('/autogenesi/detect-missing', ['year' => 2020])->assertSessionHasErrors('year');
        $this->assertStringContainsString('HTTP 503', session('errors')->first('year'));
        $this->assertNotNull(Cache::get('autogenesi-detection-research-v1-2020'));
        $this->assertNull(session('autogenesi_detection'));
        Http::assertSentCount(3);
        session()->forget('errors');
        $this->post('/autogenesi/detect-missing', ['year' => 2020])->assertSessionHasNoErrors();
        $this->assertCount(32, session('autogenesi_detection.cars'));
        Http::assertSentCount(4);
        $webCalls = Http::recorded(fn ($request) => data_get($request->data(), 'tools.0.type') === 'web_search');
        $this->assertCount(1, $webCalls);
    }

    public function test_timeout_has_specific_message_and_preserves_previous_result(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) { $calls++; throw new \Illuminate\Http\Client\ConnectionException('Operation timed out'); });
        $this->withSession(['autogenesi_detection' => ['year' => 2019]])->post('/autogenesi/detect-missing', ['year' => 2020])->assertSessionHasErrors('year');
        $this->assertStringContainsString('OpenAI non ha risposto', session('errors')->first('year'));
        $this->assertSame(['year' => 2019], session('autogenesi_detection'));
        $this->assertSame(1, $calls);
    }

    public function test_invalid_api_key_is_not_retried_and_has_specific_error(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'Unauthorized']], 401)]);
        $this->post('/autogenesi/detect-missing', ['year' => 2020])->assertSessionHasErrors('year');
        $this->assertStringContainsString('HTTP 401', session('errors')->first('year'));
        Http::assertSentCount(1);
    }

    public function test_comparison_handles_indexed_brands_aliases_and_does_not_confuse_golf_plus(): void
    {
        ConfiguratorProduct::create(['handle' => 'multi', 'category' => 'screen', 'title' => 'Radio', 'brand' => 'VW | SEAT', 'model' => '1:Golf VII | 2:Leon', 'year_from' => 2020, 'year_to' => 2020]);
        ConfiguratorProduct::create(['handle' => 'plus', 'category' => 'screen', 'title' => 'Golf', 'brand' => 'Volkswagen', 'model' => 'Golf Plus', 'year_from' => 2019, 'year_to' => 2021]);
        $result = app(AutogenesiDetection::class)->compare(['year' => 2020, 'cars' => [
            ['brand' => 'Volkswagen', 'model' => 'Golf'], ['brand' => 'Peugeot', 'model' => 'Leon'], ['brand' => 'Seat', 'model' => 'Leon'],
        ]]);
        $this->assertSame(['catalog', 'missing', 'catalog'], array_column($result['cars'], 'status'));
        $old = app(AutogenesiDetection::class)->compare(['year' => 2019, 'cars' => [['brand' => 'Volkswagen', 'model' => 'Golf']]]);
        $this->assertSame('missing', $old['cars'][0]['status']);
    }
}
