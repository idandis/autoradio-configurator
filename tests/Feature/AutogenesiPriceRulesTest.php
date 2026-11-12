<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AutogenesiPrice;
use App\Services\AutogenesiPriceRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutogenesiPriceRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_current_defaults_match_the_existing_rule_and_loading_does_not_write_settings(): void
    {
        $this->getJson(route('price-rules.show'))->assertOk()->assertJsonCount(5, 'ranges')->assertJsonPath('ranges.0.fixed', '110.00')->assertJsonPath('ranges.4.max', null);
        foreach (['100.00' => '210', '150.00' => '397', '250.00' => '555', '350.00' => '703', '450.00' => '861'] as $cost => $expected) {
            $this->assertSame($expected, app(AutogenesiPrice::class)->quote($cost)['selling_price']);
        }
        Storage::disk('local')->assertMissing('autogenesi/pricing/rules.json');
    }

    public function test_preview_does_not_save_and_saved_percentage_plus_fixed_is_read_dynamically(): void
    {
        $ranges = [['min' => '0', 'max' => null, 'percentage' => '10', 'fixed' => '20']];
        $price = app(AutogenesiPrice::class);
        $this->assertSame('210', $price->quote('100')['selling_price']);
        $this->postJson(route('price-rules.preview'), ['cost' => '120.50', 'ranges' => $ranges])->assertOk()
            ->assertJsonPath('markup_percentage', '12.05')->assertJsonPath('markup_fixed', '20.00')->assertJsonPath('selling_price', '153');
        Storage::disk('local')->assertMissing('autogenesi/pricing/rules.json');
        $this->postJson(route('price-rules.save'), ['ranges' => $ranges])->assertOk();
        $this->assertSame('130', $price->quote('100')['selling_price']);
        $this->assertSame('153', $price->quote('120.50')['selling_price']);
        $ranges[0]['percentage'] = '0'; $ranges[0]['fixed'] = '50';
        $this->postJson(route('price-rules.save'), ['ranges' => $ranges])->assertOk();
        $this->assertSame('150', $price->quote('100')['selling_price']);
    }

    public function test_gaps_overlaps_unbounded_middle_and_missing_final_range_are_rejected(): void
    {
        $valid = app(AutogenesiPriceRules::class)->defaults()['ranges'];
        foreach ([
            [['min' => 1, 'max' => null, 'percentage' => 0, 'fixed' => 10]],
            [['min' => 0, 'max' => 100, 'percentage' => 0, 'fixed' => 10]],
            [['min' => 0, 'max' => null, 'percentage' => 0, 'fixed' => 10], ['min' => 100, 'max' => null, 'percentage' => 0, 'fixed' => 20]],
            [['min' => 0, 'max' => 100, 'percentage' => 0, 'fixed' => 10], ['min' => 90, 'max' => null, 'percentage' => 0, 'fixed' => 20]],
            [['min' => 0, 'max' => 100, 'percentage' => 0, 'fixed' => 10], ['min' => 110, 'max' => null, 'percentage' => 0, 'fixed' => 20]],
        ] as $ranges) $this->postJson(route('price-rules.save'), ['ranges' => $ranges])->assertUnprocessable();
        $this->assertSame($valid, app(AutogenesiPriceRules::class)->current()['ranges']);
    }

    public function test_vat_is_previewed_saved_and_applied_to_import_and_fees(): void
    {
        $ranges = app(AutogenesiPriceRules::class)->defaults()['ranges'];
        $this->postJson(route('price-rules.preview'), ['cost' => '200', 'ranges' => $ranges, 'vat_rate' => '7'])->assertOk()
            ->assertJsonPath('vat_rate', '7.00')->assertJsonPath('import_vat_estimate', '17.42')->assertJsonPath('correos_estimate', '28.88')->assertJsonPath('selling_price', '427');
        Storage::disk('local')->assertMissing('autogenesi/pricing/rules.json');
        $this->postJson(route('price-rules.save'), ['ranges' => $ranges, 'vat_rate' => '7'])->assertOk()->assertJsonPath('vat_rate', '7.00');
        $this->assertSame('427', app(AutogenesiPrice::class)->quote('200')['selling_price']);
        $this->assertSame('210', app(AutogenesiPrice::class)->quote('100')['selling_price']);
        $this->postJson(route('price-rules.save'), ['ranges' => $ranges])->assertOk()->assertJsonPath('vat_rate', '7.00');
        foreach ([-1, 101, 'bad', '21.123', null] as $rate) {
            $this->postJson(route('price-rules.save'), ['ranges' => $ranges, 'vat_rate' => $rate])->assertUnprocessable();
        }
        $this->postJson(route('price-rules.save'), ['ranges' => $ranges, 'vat_rate' => '0'])->assertOk();
        $this->assertSame('0.00', app(AutogenesiPrice::class)->quote('200')['import_vat_estimate']);
    }

    public function test_old_saved_rules_without_vat_keep_twenty_one_percent(): void
    {
        Storage::disk('local')->put('autogenesi/pricing/rules.json', json_encode(['version' => 'old', 'ranges' => app(AutogenesiPriceRules::class)->defaults()['ranges']]));
        $this->getJson(route('price-rules.show'))->assertOk()->assertJsonPath('vat_rate', '21.00');
        $this->assertSame('21.00', app(AutogenesiPrice::class)->quote('200')['vat_rate']);
    }

    public function test_rule_saving_is_admin_and_local_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson(route('price-rules.show'))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->app['env'] = 'production';
        $this->postJson(route('price-rules.save'), ['ranges' => app(AutogenesiPriceRules::class)->defaults()['ranges']])->assertForbidden();
    }
}
