<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutogenesiBatch;
use App\Models\AutogenesiBatch;
use App\Models\AutogenesiProduct;
use App\Models\User;
use App\Services\AutogenesiApiImport;
use App\Services\AutogenesiAliExpressApi;
use App\Services\AutogenesiDescription;
use App\Services\AutogenesiExport;
use App\Services\AutogenesiImport;
use App\Services\AutogenesiList;
use App\Services\AutogenesiSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutogenesiApiImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->detectEnvironment(fn () => 'local');
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
    }

    private function product(string $origin, float $price): array
    {
        return [
            'ae_item_base_info_dto' => ['subject' => 'Radio Android Hyundai Kona 2017-2020', 'detail' => '<p>Hyundai Kona 2017-2020. Android, 8Core.</p><img src="https://127.0.0.1/private.png">'],
            'ae_item_properties' => ['ae_item_property' => [['attr_name' => 'Sistema operativo', 'attr_value' => 'Android']]],
            'ae_multimedia_info_dto' => ['image_urls' => 'http://ae01.alicdn.com/1.png;https://ae01.alicdn.com/2.png;https://ae01.alicdn.com/3.png'],
            'ae_store_info' => ['store_name' => 'Test seller'],
            'ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => [[
                'sku_id' => 'sku-1', 'sku_available_stock' => 5, 'currency_code' => 'EUR', 'offer_sale_price' => (string) $price,
                'ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [
                    ['sku_property_id' => 14, 'sku_property_name' => 'Color', 'property_value_definition_name' => '8Core 8GB 128GB'],
                    ['sku_property_id' => 200007763, 'sku_property_name' => 'Envíos desde', 'sku_property_value' => $origin],
                ]],
            ]]],
        ];
    }

    public static function criteria(): array
    {
        return [
            'prefer EU' => [[], '222', '120.00'],
            'legacy all still prefers EU' => [['origin_policy' => 'all', 'sort_by' => 'price'], '222', '120.00'],
            'sales all origins' => [['origin_policy' => 'all', 'sort_by' => 'sales'], '222', '120.00'],
            'EU only' => [['origin_policy' => 'eu'], '222', '120.00'],
            'no EU falls back to all' => [[], '111', '50.00', false],
            'best match is not cheapest' => [['sort_by' => 'best_match'], '222', '120.00', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('criteria')]
    public function test_api_creates_private_draft_with_requested_criteria_without_browser(array $options, string $expectedId, string $expectedPrice, bool $euAvailable = true): void
    {
        $api = $this->mock(AutogenesiAliExpressApi::class);
        $candidates = [
            ['id' => '111', 'title' => 'Radio Android Hyundai Kona 2017-2020', 'price' => 50, 'url' => 'https://es.aliexpress.com/item/111.html', 'sales' => 10, 'orders' => '10'],
            ['id' => '222', 'title' => 'Radio Android Hyundai Kona 2017-2020', 'price' => 120, 'url' => 'https://es.aliexpress.com/item/222.html', 'sales' => 1000, 'orders' => '1000+'],
            ['id' => '333', 'title' => 'Marco radio Hyundai Kona 2017-2020', 'price' => 2, 'url' => 'https://es.aliexpress.com/item/333.html'],
        ];
        if (($options['sort_by'] ?? '') === 'best_match') $candidates = array_reverse($candidates);
        $api->shouldReceive('search')->once()->with(1, 'radio Hyundai Kona 2017 2020', $options['sort_by'] ?? 'price')->andReturn(['products' => $candidates]);
        $api->shouldReceive('product')->once()->with(1, '111')->andReturn($this->product('China Mainland', 50));
        $api->shouldReceive('product')->once()->with(1, '222')->andReturn($this->product($euAvailable ? 'España' : 'China Mainland', 120));
        Http::fake(['https://ae01.alicdn.com/*' => function ($request) {
            $image = imagecreatetruecolor(220, 220);
            imagefill($image, 0, 0, imagecolorallocate($image, (int) basename($request->url(), '.png') * 60, 20, 50));
            ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
            return Http::response($bytes, 200, ['Content-Type' => 'image/png']);
        }]);
        $this->mock(AutogenesiDescription::class)->shouldReceive('generate')->once()->andReturn([
            'title' => 'Radio 9" 2DIN Hyundai Kona 2017-2020', 'body_html' => '<p>Android.</p>', 'notes' => [], 'variant_specs' => [],
            'compatibilities' => [['brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020, 'evidence' => 'Hyundai Kona 2017-2020', 'source_file' => null]],
        ]);
        $url = null;
        $p = app(AutogenesiApiImport::class)->importBest(1, 'radio Hyundai Kona 2017 2020', ['brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020], function ($value) use (&$url) { $url = $value; }, $options);
        $this->assertSame($expectedId, $p->source_id);
        $this->assertSame('https://es.aliexpress.com/item/'.$expectedId.'.html', $url);
        $this->assertSame('draft', $p->status);
        $this->assertCount(3, $p->images);
        $this->assertSame($expectedPrice, $p->variants[0]['supplier_price']);
        $this->assertSame($expectedId === '222' && $euAvailable ? 'EU' : 'all', $p->metadata['api_selection']['origin']);
        $this->assertSame('api', $p->metadata['acquisition_method']);
        $this->assertSame('TRASFORMA', $p->metadata['vehicle_transform']['rule']);
        $this->assertSame('HYUNDAI', $p->brand);
        $this->assertSame('Kona', $p->model);
        Http::assertSentCount(3);
        Process::assertNothingRan();
    }

    public function test_universal_and_accessories_are_not_imported(): void
    {
        $api = $this->mock(AutogenesiAliExpressApi::class);
        $api->shouldReceive('search')->once()->andReturn(['products' => [
            ['id' => '1', 'title' => 'Radio Android universal Hyundai Kona', 'price' => 10],
            ['id' => '2', 'title' => 'Marco Radio Hyundai Kona', 'price' => 2],
        ]]);
        $api->shouldNotReceive('product');
        $this->assertNull(app(AutogenesiApiImport::class)->importBest(1, 'radio Hyundai Kona 2017', ['brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2017]));
        $this->assertDatabaseCount('autogenesi_products', 0);
        Process::assertNothingRan();
    }

    public function test_real_fiesta_antenna_regression_and_disguised_accessory_are_rejected(): void
    {
        $vehicle = ['brand' => 'Ford', 'model' => 'Fiesta', 'year_from' => 2009, 'year_to' => 2009];
        $s = app(AutogenesiApiImport::class);
        $this->assertFalse($s->relevant('Para Ford Fiesta MK 4 5 6 1995 1997 1999 2001 2003 2005 2009 2011 2015 2017 AM/FM antena de Radio de techo Base aérea varilla de sellado del mástil', $vehicle));
        $p = $this->product('Spain', 5);
        $p['ae_item_base_info_dto']['subject'] = 'Radio Android Ford Fiesta 2009';
        $p['ae_item_properties']['ae_item_property'][] = ['attr_name' => 'Tipo de artículo', 'attr_value' => 'Antenas'];
        $this->assertFalse($s->headUnit($p, $vehicle));
    }

    public function test_api_batch_uses_api_and_auth_failure_pauses_without_browser(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $batch = app(AutogenesiList::class)->create('Hyundai Kona 2017 2020', $user->id, 'api');
        $this->assertSame('api', $batch->engine);
        $this->mock(AutogenesiApiImport::class)->shouldReceive('importBest')->once()->andThrow(new \RuntimeException('Autorizzazione AliExpress scaduta o assente. Ripeti l’autorizzazione.'));
        $search = $this->mock(AutogenesiSearch::class); $search->shouldNotReceive('search');
        $importer = $this->mock(AutogenesiImport::class); $importer->shouldNotReceive('capture');
        (new ProcessAutogenesiBatch($batch->id))->handle($search, $importer, $this->mock(AutogenesiExport::class));
        $this->assertSame('blocked', $batch->fresh()->status);
        $this->assertSame('blocked', $batch->items()->first()->status);
        Process::assertNothingRan();
    }

    public function test_invalid_variants_are_excluded(): void
    {
        $p = $this->product('Spain', 100);
        $valid = $p['ae_item_sku_info_dtos']['ae_item_sku_info_d_t_o'][0];
        $noStock = $valid; $noStock['sku_available_stock'] = 0;
        $usd = $valid; $usd['currency_code'] = 'USD';
        $camera360 = $valid; $camera360['sku_attr'] = '360';
        $frame = $valid; $frame['ae_sku_property_dtos']['ae_sku_property_d_t_o'][0]['property_value_definition_name'] = 'Frame only';
        $p['ae_item_sku_info_dtos']['ae_item_sku_info_d_t_o'] = [$valid, $noStock, $usd, $camera360, $frame];
        $this->assertCount(1, app(AutogenesiApiImport::class)->variants($p));
    }

    public function test_api_batch_completes_draft_and_continues_remaining_rows_without_browser(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $batch = app(AutogenesiList::class)->create("Hyundai Kona 2017 2020\nFord Focus 2011 2018", $user->id, 'api');
        Queue::fake();
        $p = AutogenesiProduct::create(['source_id' => '222', 'source_url' => 'https://es.aliexpress.com/item/222.html', 'status' => 'draft',
            'handle' => 'kona', 'title' => 'Radio', 'body_html' => '<p>Radio</p>', 'brand' => 'Hyundai', 'model' => 'Kona', 'year_from' => 2017, 'year_to' => 2020, 'images' => [], 'variants' => [], 'metadata' => []]);
        $this->mock(AutogenesiApiImport::class)->shouldReceive('importBest')->once()->andReturnUsing(function ($uid, $query, $vehicle, $selected) use ($p) { $selected($p->source_url); return $p; });
        $search = $this->mock(AutogenesiSearch::class); $search->shouldNotReceive('search');
        $importer = $this->mock(AutogenesiImport::class); $importer->shouldNotReceive('capture');
        $export = $this->mock(AutogenesiExport::class); $export->shouldReceive('writeFiles')->once()->with($p);
        (new ProcessAutogenesiBatch($batch->id))->handle($search, $importer, $export);
        $this->assertSame('completed', $batch->items()->first()->status);
        $this->assertSame($p->id, $batch->items()->first()->product_id);
        $this->assertSame('queued', $batch->items()->skip(1)->first()->status);
        Queue::assertPushed(ProcessAutogenesiBatch::class, fn ($job) => $job->batchId === $batch->id);
        Process::assertNothingRan();
    }
}
