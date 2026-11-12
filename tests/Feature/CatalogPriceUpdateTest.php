<?php

namespace Tests\Feature;

use App\Jobs\UpdateCatalogPrices;
use App\Models\ConfiguratorProduct;
use App\Models\ProductPriceUpdate;
use App\Models\User;
use App\Services\AutogenesiAliExpressApi;
use App\Services\AutogenesiAliExpressAuth;
use App\Services\AutogenesiPrice;
use App\Services\AutogenesiRunControl;
use App\Services\AutogenesiWorker;
use App\Services\CatalogPriceUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogPriceUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function product(): ConfiguratorProduct
    {
        return ConfiguratorProduct::create(['handle' => 'test', 'category' => 'screen', 'title' => 'Radio', 'price_min' => '50',
            'meta' => ['source_url' => 'https://es.aliexpress.com/item/123.html']]);
    }

    public function test_costs_match_identifiers_not_positions_and_prices_only_increase(): void
    {
        $p = $this->product();
        $a = $p->variants()->create(['sku' => '14:10#2GB CAM', 'title' => '4Core 2GB 32GB', 'price' => 50]);
        $b = $p->variants()->create(['sku' => '14:20#4GB', 'title' => '8Core 4GB 64GB', 'price' => 900]);
        $c = $p->variants()->create(['sku' => 'unknown', 'title' => '8Core 8GB 128GB', 'price' => 100]);
        $service = app(CatalogPriceUpdater::class);
        $offers = $service->offers(['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => [
            ['sku_id' => '20', 'sku_attr' => '14:20#4GB', 'sku_price' => '200.25', 'currency_code' => 'EUR'],
            ['sku_id' => '99', 'sku_attr' => '14:99#extra', 'sku_price' => '90.00', 'currency_code' => 'EUR'],
            ['sku_id' => '10', 'sku_attr' => '14:10#2GB CAM', 'sku_price' => '120.50', 'currency_code' => 'EUR'],
        ]]], '123');
        $result = $service->apply($p->id, $offers);
        $this->assertSame(1, $result['raised']); $this->assertSame(1, $result['unchanged']); $this->assertSame(1, $result['skipped']);
        $this->assertSame('231.00', $a->fresh()->price);
        $this->assertSame('900.00', $b->fresh()->price);
        $this->assertSame('200.25', $b->fresh()->meta['variant_cost']);
        $this->assertSame('100.00', $c->fresh()->price);
        $this->assertSame(3, $p->variants()->count());
        $this->assertSame('100.00', $p->fresh()->price_min);
        $again = $service->apply($p->id, $offers);
        $this->assertSame(0, $again['raised']);
    }

    public function test_ambiguous_origins_currency_and_incomplete_option_combinations_are_skipped(): void
    {
        $p = $this->product(); $p->update(['meta' => ['source_url' => 'https://es.aliexpress.com/item/123.html', 'additional_source_urls' => ['https://es.aliexpress.com/item/456.html']]]);
        $v = $p->variants()->create(['sku' => '14:10#2GB', 'price' => '100']);
        $s = app(CatalogPriceUpdater::class);
        $this->assertSame([], $s->offers(['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => [['sku_id' => '1', 'sku_price' => '50', 'currency_code' => 'USD']]]], '123'));
        $offers = [['source_id' => '123', 'sku_id' => '1', 'attr' => '14:10', 'cost' => '100.00'], ['source_id' => '456', 'sku_id' => '2', 'attr' => '14:10', 'cost' => '200.00']];
        $this->assertSame(1, $s->apply($p->id, $offers)['skipped']);
        $offers[0]['attr'] = '14:10;200007763:201336100';
        $this->assertSame(1, $s->apply($p->id, [$offers[0]])['skipped']);
        $this->assertSame('100.00', $v->fresh()->price);
        $this->assertNull($v->fresh()->meta);
    }

    public function test_supplier_sku_ids_match_without_renaming_local_variants_and_sort_by_price(): void
    {
        $p = $this->product();
        $b = $p->variants()->create(['title' => '8Core 8GB 256GB', 'sku' => 'AE-123-1', 'price' => 100, 'meta' => ['source_sku_id' => '999', 'source_product_id' => '123']]);
        $a = $p->variants()->create(['title' => '4Core 2GB 32GB', 'sku' => 'AE-123-2', 'price' => 500]);
        $s = app(CatalogPriceUpdater::class);
        $this->assertSame(1, $s->apply($p->id, [['source_id' => '123', 'sku_id' => '999', 'attr' => '', 'cost' => '200.00']])['raised']);
        $this->assertSame(app(AutogenesiPrice::class)->quote('200.00')['selling_price'].'.00', $b->fresh()->price);
        $this->assertSame([$b->id, $a->id], $p->variants->sortBy(fn ($v) => $s->variantOrder($v))->pluck('id')->all());
        $this->assertSame('8Core 8GB 256GB', $b->fresh()->title);
    }

    public function test_negative_attribute_ids_do_not_collapse_different_hardware_variants(): void
    {
        $p = $this->product();
        $a = $p->variants()->create(['sku' => '14:-9;200007763:12', 'price' => 100]);
        $b = $p->variants()->create(['sku' => '14:-7;200007763:12', 'price' => 100]);
        $s = app(CatalogPriceUpdater::class);
        $offers = $s->offers(['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => [
            ['sku_id' => '9', 'sku_attr' => '14:-9;200007763:12', 'sku_price' => '100.00', 'currency_code' => 'EUR'],
            ['sku_id' => '7', 'sku_attr' => '14:-7;200007763:12', 'sku_price' => '140.00', 'currency_code' => 'EUR'],
        ]]], '123');
        $this->assertSame(2, $s->apply($p->id, $offers)['raised']);
        $this->assertSame('210.00', $a->fresh()->price);
        $this->assertSame('250.00', $b->fresh()->price);
    }

    public function test_duplicate_local_skus_are_not_assumed_to_be_the_same_supplier_variant(): void
    {
        $p = $this->product();
        $p->variants()->create(['sku' => '14:10#7inch-1', 'title' => '7 inch', 'price' => 100]);
        $p->variants()->create(['sku' => '14:10#7inch-2', 'title' => '9 inch', 'price' => 100]);
        $r = app(CatalogPriceUpdater::class)->apply($p->id, [['source_id' => '123', 'sku_id' => '10', 'attr' => '14:10', 'cost' => '100.00']]);
        $this->assertSame(2, $r['skipped']);
        $this->assertSame(0, $r['raised']);
        $this->assertSame('100.00', $p->variants()->first()->price);
    }

    public function test_background_run_preserves_extra_supplier_variants_and_rejects_stale_generations(): void
    {
        $this->app['env'] = 'local'; Storage::fake('local'); Queue::fake();
        app(AutogenesiRunControl::class)->activate();
        $p = $this->product(); $v = $p->variants()->create(['sku' => '14:10#2GB', 'price' => 10]);
        $run = ProductPriceUpdate::create(['user_id' => 1, 'status' => 'running', 'generation' => 1, 'items' => [['id' => $p->id, 'title' => $p->title, 'status' => 'pending']]]);
        $api = \Mockery::mock(AutogenesiAliExpressApi::class);
        $api->shouldReceive('product')->once()->with(1, '123')->andReturn(['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => [['sku_id' => '10', 'sku_attr' => '14:10', 'sku_price' => '100.00', 'currency_code' => 'EUR']]]]);
        $job = new UpdateCatalogPrices($run->id, 1);
        $job->handle($api, app(CatalogPriceUpdater::class));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('210.00', $v->fresh()->price);
        $run->update(['status' => 'running', 'generation' => 2]);
        $job->handle($api, app(CatalogPriceUpdater::class));
        Queue::assertNothingPushed();
    }

    public function test_start_is_local_admin_only_and_queues_background_work(): void
    {
        Queue::fake(); Storage::fake('local');
        $this->actingAs(User::factory()->create(['is_admin' => false]))->post(route('price-update.start'))->assertForbidden();
        $this->actingAs($user = User::factory()->create(['is_admin' => true]))->post(route('price-update.start'))->assertForbidden();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->mock(AutogenesiAliExpressAuth::class, fn ($m) => $m->shouldReceive('accessToken')->andReturn('test'));
        $this->mock(AutogenesiWorker::class, fn ($m) => $m->shouldReceive('ensure')->once());
        $this->product();
        ConfiguratorProduct::create(['handle' => 'camera-excluded', 'category' => 'camera', 'title' => 'Camera', 'meta' => ['source_url' => 'https://es.aliexpress.com/item/456.html']]);
        ConfiguratorProduct::create(['handle' => 'dashcam-excluded', 'category' => 'screen', 'title' => 'Camera', 'meta' => ['type' => 'DASHCAM', 'source_url' => 'https://es.aliexpress.com/item/789.html']]);
        $this->post(route('price-update.start'))->assertRedirect();
        Queue::assertPushed(UpdateCatalogPrices::class);
        $run = ProductPriceUpdate::firstOrFail();
        $this->assertSame($user->id, $run->user_id);
        $this->assertSame(1, $run->generation);
        $this->assertCount(1, $run->items);
        $this->post(route('price-update.stop', $run))->assertRedirect();
        $this->assertSame('stopped', $run->fresh()->status);
    }

    public function test_radio_formula_cannot_be_applied_to_cameras_even_directly(): void
    {
        $p = $this->product(); $p->update(['category' => 'camera']);
        $v = $p->variants()->create(['sku' => '14:10', 'price' => '40.26']);
        try {
            app(CatalogPriceUpdater::class)->apply($p->id, [['source_id' => '123', 'sku_id' => '10', 'attr' => '14:10', 'cost' => '12.49']]);
            $this->fail('La camera non deve usare la regola autoradio.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('autoradio', $e->getMessage());
        }
        $this->assertSame('40.26', $v->fresh()->price);
        $this->assertNull($v->fresh()->meta);
    }

    public function test_reset_archives_results_and_preserves_prices_and_manual_matches_without_dispatching(): void
    {
        Queue::fake(); Storage::fake('local');
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $user = User::factory()->create(['is_admin' => true]);
        $p = $this->product();
        $v = $p->variants()->create(['sku' => 'local', 'price' => 400, 'meta' => ['variant_cost' => '100.00', 'source_sku_id' => '999', 'source_product_id' => '123']]);
        $items = [['id' => $p->id, 'status' => 'completed']];
        $run = ProductPriceUpdate::create(['user_id' => $user->id, 'status' => 'running', 'generation' => 1, 'items' => $items]);
        $this->actingAs($user)->withSession(['autogenesi_price_research' => ['old' => true]])
            ->post(route('price-update.reset', $run))->assertRedirect()->assertSessionMissing('autogenesi_price_research');
        $this->assertSame('archived', $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->generation);
        $this->assertSame($items, $run->fresh()->items);
        $this->assertSame('400.00', $v->fresh()->price);
        $this->assertSame('999', $v->fresh()->meta['source_sku_id']);
        $this->assertNull(app(\App\Http\Controllers\CatalogPriceUpdateController::class)->snapshot($user->id));
        Queue::assertNothingPushed();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('price-update.reset', $run))->assertForbidden();
    }

    public function test_deleted_products_disappear_from_price_update_rows_and_counters(): void
    {
        $this->app['env'] = 'local';
        $run = ProductPriceUpdate::create(['user_id' => 1, 'status' => 'completed', 'items' => [
            ['id' => 999999, 'title' => 'Prodotto eliminato', 'status' => 'completed', 'result' => ['raised' => 3, 'skipped' => 2]],
        ]]);
        $data = app(\App\Http\Controllers\CatalogPriceUpdateController::class)->snapshot(1);
        $this->assertSame([], $data['items']);
        $this->assertSame(['raised' => 0, 'unchanged' => 0, 'skipped' => 0], $data['counts']);
        $this->assertCount(1, $run->fresh()->items);
    }

    public function test_memory_matching_recovers_stale_skus_while_excluding_dvr_and_360(): void
    {
        Storage::fake('local');
        $p = $this->product();
        foreach (['4Core 4G 32G', '8Core 4G 64G', '8Core 8G 128G', '8Core 12G 128G 2K'] as $i => $label) {
            $p->variants()->create(['title' => $label, 'sku' => '14:999#Old Camera DVR;200007763:'.(100 + $i), 'price' => 900]);
        }
        $rows = [];
        foreach (['4G-32G CAM', '4G-64G CAM', '2K 8G-128G CAM', '2K 12G-128G CAM', '4G-128G CAM', '4G-32G CAM DVR', '2K 12G-128G 360'] as $i => $label) {
            $rows[] = ['sku_id' => (string) ($i + 1), 'sku_attr' => '14:'.($i + 1), 'sku_price' => '100.00', 'currency_code' => 'EUR',
                'ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [['sku_property_id' => 14, 'property_value_definition_name' => $label]]]];
        }
        $s = app(CatalogPriceUpdater::class);
        $offers = $s->offers(['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => $rows]], '123');
        $this->assertCount(5, $offers);
        $result = $s->apply($p->id, $offers, null, null, true);
        $this->assertSame(0, $result['skipped']);
        $this->assertCount(4, $result['variants']);
        $this->assertSame(['memory_features'], array_values(array_unique(array_column($result['variants'], 'match_method'))));
        $this->assertSame(4, $p->variants()->count());
        $this->assertSame('210.00', $p->variants()->first()->price);
    }

    public function test_name_matching_distinguishes_spanish_cores_and_p_s_prefixes(): void
    {
        Storage::fake('local');
        $p = $this->product();
        $a = $p->variants()->create(['title' => '4Core 4G 64G', 'price' => 100]);
        $b = $p->variants()->create(['title' => '8Core 4G 64G', 'price' => 100]);
        $s = app(CatalogPriceUpdater::class);
        foreach ([['P3CP (4 Núcleos, 4G, 64G) IA', 'S4 (8 Núcleos, 4G, 64G)'], ['P3CP 4G 64G', 'S4 4G 64G'], ['S4 (4 Núcleos, 4G, 64G)', 'P3CP (8 Núcleos, 4G, 64G)']] as $labels) {
            $offers = [];
            foreach ($labels as $i => $label) $offers[] = ['source_id' => '123', 'sku_id' => (string) ($i + 1), 'label' => $label, 'attr' => '', 'cost' => '100.00'];
            $this->assertSame(0, $s->apply($p->id, $offers, null, null, true, true)['skipped']);
            $this->assertSame('1', $a->fresh()->meta['source_sku_id']);
            $this->assertSame('2', $b->fresh()->meta['source_sku_id']);
        }
    }

    public function test_equivalent_variants_prefer_warehouse_nearest_spain(): void
    {
        Storage::fake('local'); $p = $this->product();
        $v = $p->variants()->create(['title' => '8Core 6G-64G', 'price' => 100]);
        $s = app(CatalogPriceUpdater::class); $offers = [];
        foreach (['Polonia', 'Francia', 'España'] as $i => $country) $offers[] = ['source_id' => '123', 'sku_id' => (string) $i, 'label' => 'C-QS6 (8cores-6G 64G) · '.$country, 'attr' => '', 'cost' => '219.00'];
        $this->assertSame(0, $s->apply($p->id, $offers, null, null, true, true)['skipped']);
        $this->assertSame('2', $v->fresh()->meta['source_sku_id']);
        array_pop($offers);
        $this->assertSame(0, $s->apply($p->id, $offers, null, null, true, true)['skipped']);
        $this->assertSame('1', $v->fresh()->meta['source_sku_id']);
        $offers[] = array_replace($offers[1], ['sku_id' => 'duplicate']);
        $this->assertSame(1, $s->apply($p->id, $offers, null, null, true, true)['skipped']);
    }

    public function test_disappeared_supplier_sku_falls_back_to_memory_but_ambiguous_names_stay_unmatched(): void
    {
        Storage::fake('local');
        $p = $this->product();
        $v = $p->variants()->create(['title' => '8Core 4GB 64GB', 'sku' => 'old', 'price' => 100,
            'meta' => ['source_product_id' => '123', 'source_sku_id' => 'removed']]);
        $s = app(CatalogPriceUpdater::class);
        $offer = ['source_id' => '123', 'sku_id' => 'new', 'label' => '4G-64G CAM', 'attr' => '14:10', 'cost' => '100.00'];
        $duplicate = array_replace($offer, ['sku_id' => 'other', 'label' => '4GB 64GB CAM']);
        $this->assertSame(1, $s->apply($p->id, [$offer, $duplicate])['skipped']);
        $this->assertSame('removed', $v->fresh()->meta['source_sku_id']);
        $this->assertSame(0, $s->apply($p->id, [$offer])['skipped']);
        $this->assertSame('new', $v->fresh()->meta['source_sku_id']);
    }

    public function test_resume_retries_yellow_and_pending_only_preserving_red_blue_green_and_manual_fixes(): void
    {
        Queue::fake(); Storage::fake('local'); $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $this->mock(AutogenesiAliExpressAuth::class, fn ($m) => $m->shouldReceive('accessToken')->once()->andReturn('test'));
        $this->mock(AutogenesiWorker::class, fn ($m) => $m->shouldReceive('ensure')->once());
        $items = [];
        foreach (['red', 'yellow', 'blue', 'green', 'manual', 'pending'] as $color) {
            $p = ConfiguratorProduct::create(['handle' => $color, 'category' => 'screen', 'title' => $color]);
            if ($color === 'manual') $p->variants()->create(['title' => 'Radio', 'price' => 400, 'meta' => [
                'source_product_id' => '123', 'source_sku_id' => '999', 'supplier_match_manual' => ['matched_at' => now()->toIso8601String()],
                'price_update' => ['source_product_id' => '123', 'source_sku_id' => '999'],
            ]]);
            $status = in_array($color, ['red', 'manual'], true) ? 'error' : ($color === 'pending' ? 'pending' : 'completed');
            $variants = match ($color) { 'yellow' => [['status' => 'skipped']], 'blue' => [['status' => 'raised'], ['status' => 'skipped']], default => [['status' => 'unchanged']] };
            $items[] = ['id' => $p->id, 'title' => $color, 'status' => $status, 'result' => ['variants' => $variants]];
        }
        $run = ProductPriceUpdate::create(['user_id' => $user->id, 'status' => 'stopped', 'generation' => 1, 'items' => $items]);
        $this->post(route('price-update.resume', $run))->assertRedirect();
        $states = array_column($run->fresh()->items, 'status', 'title');
        $this->assertSame(['red' => 'error', 'yellow' => 'pending', 'blue' => 'completed', 'green' => 'completed', 'manual' => 'completed', 'pending' => 'pending'], $states);
        Queue::assertPushed(UpdateCatalogPrices::class, 1);
        $run->refresh()->update(['status' => 'completed', 'items' => array_values(array_filter($run->items, fn ($i) => in_array($i['title'], ['red', 'blue', 'green', 'manual'], true)))]);
        $this->post(route('price-update.resume', $run))->assertRedirect();
        $this->assertSame('completed', $run->fresh()->status);
        Queue::assertPushed(UpdateCatalogPrices::class, 1);
    }
}
