<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Services\CatalogPriceUpdater;
use App\Services\RadioVariantConfigurations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RadioVariantConfigurationMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); Storage::fake('local');
    }

    private function product(): ConfiguratorProduct
    {
        return ConfiguratorProduct::create(['handle' => 'radio-'.bin2hex(random_bytes(4)), 'title' => 'Radio Ford', 'category' => 'screen',
            'meta' => ['source_url' => 'https://es.aliexpress.com/item/123.html']]);
    }

    private function raw(string $brand, array $labels, string $cpu = ''): array
    {
        return ['ae_item_base_info_dto' => ['subject' => $brand.' car radio '.$cpu],
            'ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => array_map(fn ($label, $i) => ['sku_id' => (string) ($i + 1), 'sku_price' => '100.00', 'currency_code' => 'EUR',
                'ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [['property_value_definition_name' => $label]]]], $labels, array_keys($labels))]];
    }

    public function test_known_brands_match_table_codes_to_app_memory_and_cpu(): void
    {
        $s = app(CatalogPriceUpdater::class);
        foreach (['OSSURET', 'HIZPO'] as $brand) foreach (['QS1' => '8Core 3GB 32GB', 'QS2' => '8Core 4GB 32GB', 'QS4' => '8Core 4GB 32GB',
            'QS6' => '8Core 6GB 64GB', 'QS8' => '8Core 8GB 128GB', 'T1' => '4Core 1GB 32GB', 'T2CP' => '4Core 2GB 32GB', 'T3CP' => '4Core 2GB 64GB', 'P1' => '4Core 1GB 32GB', 'P2CP' => '4Core 2GB 32GB', 'P3CP' => '4Core 2GB 64GB'] as $code => $name) {
            $p = $this->product(); $v = $p->variants()->create(['title' => $name, 'sku' => 'legacy', 'price' => 100]);
            $result = $s->apply($p->id, $s->offers($this->raw($brand, [$code]), '123'), null, null, true, $brand === 'HIZPO');
            $this->assertSame(0, $result['skipped'], $brand.' '.$code);
            $this->assertSame($code, $v->fresh()->meta['price_update']['configuration_code']);
            $this->assertSame($name, $v->fresh()->title);
        }
    }

    public function test_unknown_brands_do_not_inherit_the_table_without_family_evidence(): void
    {
        $s = app(CatalogPriceUpdater::class); $p = $this->product();
        $p->variants()->create(['title' => '8Core 6GB 64GB', 'price' => 100]);
        $raw = $this->raw('Other brand', ['QS4', 'QS6', 'T3CP']);
        $this->assertNull(app(RadioVariantConfigurations::class)->profile($raw));
        $this->assertSame(1, $s->apply($p->id, $s->offers($raw, '123'), null, null, true, true)['skipped']);
        $raw = $this->raw('Other brand', ['QS4', 'QS6', 'T3CP'], 'Qualcomm 6115');
        $this->assertSame(RadioVariantConfigurations::PROFILE, app(RadioVariantConfigurations::class)->profile($raw));
        $this->assertSame(0, $s->apply($p->id, $s->offers($raw, '123'), null, null, true, true)['skipped']);
    }

    public function test_qs2_and_qs4_are_ambiguous_by_memory_but_exact_code_disambiguates(): void
    {
        $s = app(CatalogPriceUpdater::class); $p = $this->product();
        $v = $p->variants()->create(['title' => '8Core 4GB 32GB', 'price' => 100]);
        $offers = $s->offers($this->raw('HIZPO', ['QS2', 'QS4']), '123');
        $this->assertSame(1, $s->apply($p->id, $offers, null, null, true, true)['skipped']);
        $v->update(['title' => 'QS4']);
        $this->assertSame(0, $s->apply($p->id, $offers, null, null, true, true)['skipped']);
        $this->assertSame('2', $v->fresh()->meta['source_sku_id']);
    }

    public function test_p1l_memory_is_known_but_cpu_is_not_assumed_and_p_is_not_t(): void
    {
        $s = app(CatalogPriceUpdater::class); $p = $this->product();
        $v = $p->variants()->create(['title' => '1GB 16GB', 'price' => 100]);
        $offers = $s->offers($this->raw('HIZPO', ['P1L']), '123');
        $this->assertSame(0, $s->apply($p->id, $offers, null, null, true, true)['skipped']);
        $this->assertSame('P1L', $v->fresh()->meta['price_update']['configuration_code']);
        $v->update(['title' => 'P3CP']);
        $this->assertSame(1, $s->apply($p->id, $s->offers($this->raw('HIZPO', ['T3CP']), '123'), null, null, true, true)['skipped']);
        $this->assertNull(app(RadioVariantConfigurations::class)->profile($this->raw('Other brand', ['P1', 'P2CP', 'P3CP'])));
        $this->assertSame(RadioVariantConfigurations::PROFILE, app(RadioVariantConfigurations::class)->profile($this->raw('Other brand', ['QS4', 'QS6', 'P3CP'], 'Allwinner A100')));
    }

    public function test_s_series_is_an_alias_of_qs_only_in_the_recognized_profile(): void
    {
        $s = app(CatalogPriceUpdater::class);
        foreach (['S1' => '8Core 3GB 32GB', 'S2' => '8Core 4GB 32GB', 'S4' => '8Core 4GB 32GB', 'S6' => '8Core 6GB 64GB', 'S8' => '8Core 8GB 128GB'] as $code => $name) {
            $p = $this->product(); $v = $p->variants()->create(['title' => $name, 'price' => 100]);
            $this->assertSame(0, $s->apply($p->id, $s->offers($this->raw('HIZPO', [$code]), '123'), null, null, true, true)['skipped']);
            $this->assertSame('Q'.$code, $v->fresh()->meta['price_update']['configuration_code']);
            $v->update(['title' => 'Q'.$code]);
            $this->assertSame(0, $s->apply($p->id, $s->offers($this->raw('OSSURET', [$code]), '123'), null, null, true, true)['skipped']);
        }
        $p = $this->product(); $p->variants()->create(['title' => '8Core 6GB 64GB', 'price' => 100]);
        $this->assertSame(1, $s->apply($p->id, $s->offers($this->raw('Other brand', ['S6']), '123'), null, null, true, true)['skipped']);
        $this->assertSame('QS6', app(RadioVariantConfigurations::class)->code('QS6 / S6'));
        $this->assertNull(app(RadioVariantConfigurations::class)->code('S9'));
    }

    public function test_conflicting_specifications_unknown_codes_and_t0_cpu_stay_unmatched(): void
    {
        $s = app(CatalogPriceUpdater::class);
        foreach ([['8Core 4GB 64GB', 'QS6 4GB 64GB'], ['4Core 6GB 64GB', 'QS6'], ['4Core 1GB 16GB', 'T0'], ['4Core 1GB 16GB', 'P1L'], ['8Core 8GB 256GB', 'QS9']] as [$name, $label]) {
            $p = $this->product(); $p->variants()->create(['title' => $name, 'price' => 100]);
            $this->assertSame(1, $s->apply($p->id, $s->offers($this->raw('OSSURET', [$label]), '123'), null, null, true, true)['skipped']);
        }
    }
}
