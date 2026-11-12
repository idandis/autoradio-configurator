<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\User;
use App\Services\AutogenesiAliExpressApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogVariantMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function product(): ConfiguratorProduct
    {
        return ConfiguratorProduct::create(['handle' => 'radio', 'category' => 'screen', 'title' => 'Radio', 'price_min' => 50,
            'meta' => ['source_url' => 'https://es.aliexpress.com/item/123.html']]);
    }

    private function response(): array
    {
        return ['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => [
            ['sku_id' => '999', 'sku_attr' => '14:-9', 'sku_price' => '100.00', 'currency_code' => 'EUR',
                'ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [['sku_property_id' => 14, 'sku_property_value' => 'PX9 8GB 128GB']]]],
            ['sku_id' => '888', 'sku_price' => '90.00', 'currency_code' => 'EUR', 'sku_available_stock' => 0,
                'ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [['sku_property_id' => 14, 'sku_property_value' => 'PX1 2GB 32GB']]]],
        ]]];
    }

    public function test_can_add_variant_without_supplier_and_match_it_later(): void
    {
        $p = $this->product();
        $p->variants()->create(['title' => 'Base', 'sku' => 'base', 'price' => 400]);
        $this->postJson(route('supplier-variants.create-manual', $p), ['title' => '8Core 8GB 128GB'])
            ->assertOk()->assertJsonPath('app_variant.label', '8Core 8GB 128GB')
            ->assertJsonPath('app_variant.sku_id', null);
        $v = $p->variants()->where('title', '8Core 8GB 128GB')->firstOrFail();
        $this->assertNull($v->price);
        $this->assertNull($v->meta['source_sku_id'] ?? null);
        $this->assertSame('400.00', $p->fresh()->price_min);

        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andReturn($this->response()));
        $this->postJson(route('supplier-variants.match', [$p, $v]), ['source_id' => '123', 'sku_id' => '999'])
            ->assertOk();
        $this->assertSame('999', $v->fresh()->meta['source_sku_id']);
        $this->assertSame('210.00', $v->fresh()->price);
    }

    public function test_compares_real_names_and_manual_pairing_changes_only_selected_variant(): void
    {
        $p = $this->product();
        $v = $p->variants()->create(['title' => '8Core 8GB 128GB', 'sku' => 'local-1', 'price' => 50]);
        $other = $p->variants()->create(['title' => '2GB 32GB', 'sku' => 'local-2', 'price' => 60]);
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->twice()->andReturn($this->response()));
        $this->getJson(route('supplier-variants.show', $p))->assertOk()->assertJsonPath('app.0.label', '8Core 8GB 128GB')
            ->assertJsonPath('aliexpress.0.label', 'PX9 8GB 128GB')->assertJsonPath('aliexpress.1.available', false);
        $this->postJson(route('supplier-variants.match', [$p, $v]), ['source_id' => '123', 'sku_id' => '999'])
            ->assertOk()->assertJsonPath('result.raised', 1);
        $this->assertSame('210.00', $v->fresh()->price);
        $this->assertSame('999', $v->fresh()->meta['source_sku_id']);
        $this->assertSame('100.00', $v->fresh()->meta['variant_cost']);
        $this->assertSame('local-1', $v->fresh()->sku);
        $this->assertSame('8Core 8GB 128GB', $v->fresh()->title);
        $this->assertSame('60.00', $other->fresh()->price);
        $this->assertNull($other->fresh()->meta);
    }

    public function test_unavailable_skus_and_foreign_sources_are_rejected_without_mutation(): void
    {
        $p = $this->product(); $v = $p->variants()->create(['title' => 'Radio', 'price' => 400]);
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andReturn($this->response()));
        $url = route('supplier-variants.match', [$p, $v]);
        $this->postJson($url, ['source_id' => '123', 'sku_id' => '888'])->assertUnprocessable();
        $this->postJson($url, ['source_id' => '456', 'sku_id' => '999'])->assertUnprocessable();
        $this->assertNull($v->fresh()->meta);
        $this->assertSame('400.00', $v->fresh()->price);
    }

    public function test_camera_rule_and_non_admin_manual_pairing_are_blocked(): void
    {
        $p = $this->product(); $p->update(['category' => 'camera']);
        $v = $p->variants()->create(['title' => 'AHD', 'price' => '40.26']);
        $url = route('supplier-variants.match', [$p, $v]);
        $this->postJson($url, ['source_id' => '123', 'sku_id' => '999'])->assertUnprocessable();
        $this->assertSame('40.26', $v->fresh()->price);
        $this->actingAs(User::factory()->create(['is_admin' => false]))->postJson($url, ['source_id' => '123', 'sku_id' => '999'])->assertForbidden();
    }

    public function test_new_link_preview_is_read_only_and_pairing_saves_link_cost_price_atomically(): void
    {
        $p = $this->product();
        $p->update(['handle' => 'radio-ae-123', 'meta' => ['source_url' => 'https://es.aliexpress.com/item/123.html', 'autogenesi' => ['source_url' => 'https://es.aliexpress.com/item/123.html']]]);
        $v = $p->variants()->create(['title' => '8Core 8GB 128GB', 'sku' => 'local-1', 'price' => 50]);
        $other = $p->variants()->create(['title' => '4GB', 'sku' => '14:-9', 'price' => 900]);
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->twice()->withArgs(fn ($user, $id) => $id === '456')->andReturn($this->response()));
        $this->postJson(route('supplier-variants.preview', $p), ['source_url' => 'https://www.aliexpress.com/item/456.html?foo=bar'])->assertOk()
            ->assertJsonPath('aliexpress.0.source_id', '456')->assertJsonPath('aliexpress.0.minimum', '210');
        $this->assertSame('https://es.aliexpress.com/item/123.html', $p->fresh()->meta['source_url']);
        $this->postJson(route('supplier-variants.match', [$p, $v]), ['source_id' => '456', 'sku_id' => '999', 'replacement_url' => 'https://es.aliexpress.com/item/456.html'])
            ->assertOk()->assertJsonPath('result.raised', 1);
        $this->assertSame('https://es.aliexpress.com/item/456.html', $p->fresh()->meta['source_url']);
        $this->assertSame('https://es.aliexpress.com/item/456.html', $p->fresh()->meta['autogenesi']['source_url']);
        $this->assertSame(['456'], app(\App\Services\CatalogPriceUpdater::class)->sources($p->fresh()));
        $this->assertSame('210.00', $v->fresh()->price);
        $this->assertSame('456', $v->fresh()->meta['source_product_id']);
        $this->assertSame('999', $v->fresh()->meta['source_sku_id']);
        $this->assertSame('900.00', $other->fresh()->price);
        $this->assertTrue($other->fresh()->meta['supplier_match_required']);
        $s = app(\App\Services\CatalogPriceUpdater::class);
        $this->assertSame(1, $s->apply($p->id, $s->offers($this->response(), '456'))['skipped']);
        $this->assertSame('900.00', $other->fresh()->price);
    }

    public function test_invalid_link_and_unavailable_replacement_do_not_change_product_link_or_price(): void
    {
        $p = $this->product(); $v = $p->variants()->create(['title' => 'Radio', 'price' => 400]);
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andReturn($this->response()));
        $this->postJson(route('supplier-variants.preview', $p), ['source_url' => 'https://aliexpress.com.evil.test/item/456.html'])->assertUnprocessable();
        $this->postJson(route('supplier-variants.match', [$p, $v]), ['source_id' => '456', 'sku_id' => '888', 'replacement_url' => 'https://es.aliexpress.com/item/456.html'])->assertUnprocessable();
        $this->assertSame('https://es.aliexpress.com/item/123.html', $p->fresh()->meta['source_url']);
        $this->assertSame('400.00', $v->fresh()->price);
        $this->assertNull($v->fresh()->meta);
    }

    public function test_saved_manual_match_turns_error_row_blue_after_reload_without_rewriting_the_job(): void
    {
        $p = $this->product();
        $meta = ['source_product_id' => '123', 'source_sku_id' => '999',
            'supplier_match_manual' => ['matched_at' => now()->toIso8601String()],
            'price_update' => ['source_product_id' => '123', 'source_sku_id' => '999']];
        $v = $p->variants()->create(['title' => 'Radio', 'price' => 400, 'meta' => $meta]);
        $items = [['id' => $p->id, 'title' => $p->title, 'status' => 'error', 'error' => 'Errore AliExpress 604']];
        $run = \App\Models\ProductPriceUpdate::create(['user_id' => auth()->id(), 'status' => 'completed', 'items' => $items]);
        $snapshot = app(\App\Http\Controllers\CatalogPriceUpdateController::class)->snapshot(auth()->id());
        $this->assertTrue($snapshot['items'][0]['manual_updated']);
        $this->assertSame('manual_updated', $snapshot['items'][0]['status']);
        $this->assertArrayNotHasKey('error', $snapshot['items'][0]);
        $this->assertSame($items, $run->fresh()->items);
        $meta['supplier_match_manual']['matched_at'] = now()->subDay()->toIso8601String();
        $v->update(['meta' => $meta]);
        $this->assertFalse(app(\App\Http\Controllers\CatalogPriceUpdateController::class)->snapshot(auth()->id())['items'][0]['manual_updated']);
    }

    public function test_can_delete_selected_local_variant_and_refresh_minimum_but_preserves_last_variant(): void
    {
        $p = $this->product();
        $a = $p->variants()->create(['title' => '2GB', 'sku' => 'small', 'price' => 100]);
        $b = $p->variants()->create(['title' => '8GB', 'sku' => 'large', 'price' => 400]);
        $this->deleteJson(route('supplier-variants.destroy', [$p, $a]))->assertOk();
        $this->assertDatabaseMissing('configurator_variants', ['id' => $a->id]);
        $this->assertSame('400.00', $p->fresh()->price_min);
        $this->assertSame('small', $p->fresh()->meta['deleted_variants'][0]['variant']['sku']);
        $this->assertSame(1, $p->variants()->count());
        $this->deleteJson(route('supplier-variants.destroy', [$p, $b]))->assertUnprocessable();
        $this->assertDatabaseHas('configurator_variants', ['id' => $b->id]);
        $other = ConfiguratorProduct::create(['handle' => 'other', 'category' => 'screen', 'title' => 'Other']);
        $this->deleteJson(route('supplier-variants.destroy', [$other, $b]))->assertNotFound();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->deleteJson(route('supplier-variants.destroy', [$p, $b]))->assertForbidden();
    }

    public function test_explicitly_adding_supplier_variant_saves_formula_price_and_prevents_duplicates(): void
    {
        $p = $this->product();
        $old = $p->variants()->create(['title' => 'Old', 'price' => 900]);
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->twice()->andReturn($this->response()));
        $url = route('supplier-variants.create', $p);
        $input = ['source_id' => '123', 'sku_id' => '999', 'title' => '8Core 8GB 128GB'];
        $this->postJson($url, $input)->assertOk()->assertJsonPath('app_variant.label', '8Core 8GB 128GB')->assertJsonPath('app_variant.price', '210.00');
        $this->assertSame(2, $p->variants()->count());
        $v = $p->variants()->whereKeyNot($old->id)->firstOrFail();
        $this->assertSame('100.00', $v->meta['variant_cost']);
        $this->assertSame('999', $v->meta['source_sku_id']);
        $this->assertNull($v->shopify_variant_id);
        $this->assertSame('210.00', $p->fresh()->price_min);
        $this->assertSame('900.00', $old->fresh()->price);
        $this->postJson($url, $input)->assertUnprocessable();
        $this->assertSame(2, $p->variants()->count());
    }

    public function test_manual_pairing_resets_higher_or_missing_price_to_the_active_rule(): void
    {
        $p = $this->product();
        $v = $p->variants()->create(['title' => '8GB', 'price' => 900]);
        $other = $p->variants()->create(['title' => 'Other', 'price' => 500]);
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->twice()->andReturn($this->response()));
        $url = route('supplier-variants.match', [$p, $v]);
        $data = ['source_id' => '123', 'sku_id' => '999'];
        $this->postJson($url, $data)->assertOk()->assertJsonPath('result.variants.0.status', 'recalculated');
        $this->assertSame('210.00', $v->fresh()->price);
        $this->assertSame('210.00', $p->fresh()->price_min);
        $this->assertSame('reset_to_rule', $v->fresh()->meta['price_update']['mode']);
        $this->assertSame('500.00', $other->fresh()->price);
        $v->update(['price' => null]);
        $this->postJson($url, $data)->assertOk();
        $this->assertSame('210.00', $v->fresh()->price);
    }

    public function test_final_price_can_be_edited_after_pairing_without_changing_cost_or_rule_reference(): void
    {
        $p = $this->product(); $v = $p->variants()->create(['title' => '8GB', 'price' => 900]);
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->twice()->andReturn($this->response()));
        $this->postJson(route('supplier-variants.match', [$p, $v]), ['source_id' => '123', 'sku_id' => '999'])->assertOk();
        $this->patch(route('imported-products.variants', $p), ['variants' => [['id' => $v->id, 'price' => '199.50']]])->assertRedirect();
        $this->assertSame('199.50', $v->fresh()->price);
        $this->assertSame('199.50', $p->fresh()->price_min);
        $this->assertSame('100.00', $v->fresh()->meta['variant_cost']);
        $this->assertSame('999', $v->fresh()->meta['source_sku_id']);
        $this->getJson(route('supplier-variants.show', $p))->assertOk()->assertJsonPath('app.0.price', '199.50')->assertJsonPath('app.0.rule_price', '210')
            ->assertJsonPath('app.0.supplier_cost', '100.00')->assertJsonPath('app.0.supplier_label', 'PX9 8GB 128GB')->assertJsonPath('app.0.sku_id', '999');
    }

    public function test_variant_list_reorders_after_final_price_changes(): void
    {
        $p = $this->product();
        $expensive = $p->variants()->create(['title' => '2G 32G 8Core', 'price' => 469]);
        $cheap = $p->variants()->create(['title' => '2G 64G 4Core', 'price' => 285]);
        $this->getJson(route('supplier-variants.show', $p))->assertOk()->assertJsonPath('app.0.id', $cheap->id);
        $this->patch(route('imported-products.variants', $p), ['variants' => [['id' => $expensive->id, 'price' => '250.00']]])->assertRedirect();
        $this->getJson(route('supplier-variants.show', $p))->assertOk()->assertJsonPath('app.0.id', $expensive->id);
    }

    public function test_attempt_with_new_link_saves_unambiguous_name_matches_and_leaves_other_variants(): void
    {
        $p = $this->product();
        $v = $p->variants()->create(['title' => '8Core 8GB 128GB', 'sku' => 'old-sku', 'price' => 900]);
        $other = $p->variants()->create(['title' => '2GB 32GB', 'price' => 300]);
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->withArgs(fn ($user, $id) => $id === '456')->andReturn($this->response()));
        $this->postJson(route('supplier-variants.attempt', $p), ['source_url' => 'https://es.aliexpress.com/item/456.html'])
            ->assertOk()->assertJsonPath('result.skipped', 1);
        $this->assertSame('210.00', $v->fresh()->price);
        $this->assertSame('999', $v->fresh()->meta['source_sku_id']);
        $this->assertArrayNotHasKey('supplier_match_required', $v->fresh()->meta);
        $this->assertSame('300.00', $other->fresh()->price);
        $this->assertTrue($other->fresh()->meta['supplier_match_required']);
        $this->assertSame('https://es.aliexpress.com/item/456.html', $p->fresh()->meta['source_url']);
    }

    public function test_name_match_prefers_plain_radio_over_same_memory_with_camera_or_hdt(): void
    {
        $p = $this->product();
        $v = $p->variants()->create(['title' => '2G 32G 8Core', 'price' => 500]);
        $offers = [
            ['source_id' => '123', 'sku_id' => 'cam', 'label' => '2G 32G 8Core CAM · China Mainland · núcleo cuádruple', 'cost' => '204.05', 'attr' => ''],
            ['source_id' => '123', 'sku_id' => 'plain', 'label' => '2G 32G 8Core · China Mainland · núcleo cuádruple', 'cost' => '187.94', 'attr' => ''],
            ['source_id' => '123', 'sku_id' => 'hdt', 'label' => '2G 32G 8Core HDT · China Mainland · núcleo cuádruple', 'cost' => '210.00', 'attr' => ''],
        ];
        $result = app(\App\Services\CatalogPriceUpdater::class)->apply($p->id, $offers, null, null, true, true);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame('plain', $v->fresh()->meta['source_sku_id']);
        $this->assertSame('187.94', $v->fresh()->meta['variant_cost']);
    }

    public function test_attempt_with_no_name_matches_keeps_original_link_and_prices(): void
    {
        $p = $this->product(); $v = $p->variants()->create(['title' => '4GB 64GB', 'price' => 900]);
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andReturn($this->response()));
        $this->postJson(route('supplier-variants.attempt', $p), ['source_url' => 'https://es.aliexpress.com/item/456.html'])->assertUnprocessable();
        $this->assertSame('900.00', $v->fresh()->price);
        $this->assertNull($v->fresh()->meta);
        $this->assertSame('https://es.aliexpress.com/item/123.html', $p->fresh()->meta['source_url']);
    }

    public function test_can_rename_local_variant_without_changing_price_cost_or_shopify_identity(): void
    {
        $p = $this->product();
        $meta = ['source_sku_id' => '999', 'variant_cost' => '100.00'];
        $v = $p->variants()->create(['title' => 'Old', 'option_value' => 'Old', 'sku' => 'keep-sku', 'shopify_variant_id' => '123456', 'price' => 400, 'meta' => $meta]);
        $url = route('supplier-variants.rename', [$p, $v]);
        $this->patchJson($url, ['title' => '  8Core 8GB 128GB  '])->assertOk()->assertJsonPath('title', '8Core 8GB 128GB');
        $this->assertSame('8Core 8GB 128GB', $v->fresh()->title);
        $this->assertSame('8Core 8GB 128GB', $v->fresh()->option_value);
        $this->assertSame('400.00', $v->fresh()->price);
        $this->assertSame($meta, $v->fresh()->meta);
        $this->assertSame('keep-sku', $v->fresh()->sku);
        $this->assertSame('123456', (string) $v->fresh()->shopify_variant_id);
        $this->patchJson($url, ['title' => ''])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->patchJson($url, ['title' => 'Changed'])->assertForbidden();
    }

    public function test_recreate_replaces_only_radio_variants_and_recalculates_prices(): void
    {
        $p = $this->product();
        $old = $p->variants()->create(['title' => 'Old', 'sku' => 'old', 'price' => 900]);
        $rows = [
            ['999', 'QS6 8Core 6G 64G', '100.00'], ['998', 'P2 4Core 2G 32G', '90.00'],
            ['997', 'P1 AI AHDC6', '70.00'], ['996', 'QS6 DVR', '110.00'],
            ['995', 'QS8 360', '120.00'], ['994', 'Marco adaptador', '15.00'],
            ['993', 'OBD cable', '10.00'],
        ];
        $raw = ['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => array_map(fn ($r) => [
            'sku_id' => $r[0], 'sku_price' => $r[2], 'currency_code' => 'EUR',
            'ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [['sku_property_id' => 14, 'sku_property_value' => $r[1]]]],
        ], $rows)]];
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->twice()->withArgs(fn ($user, $id) => $id === '123')->andReturn($raw));
        $this->postJson(route('supplier-variants.recreate', $p))->assertOk()->assertJsonPath('created', 2)->assertJsonPath('excluded', 5);
        $this->assertDatabaseMissing('configurator_variants', ['id' => $old->id]);
        $this->assertEqualsCanonicalizing(['999', '998'], $p->variants()->get()->map(fn ($v) => $v->meta['source_sku_id'])->all());
        $this->assertEqualsCanonicalizing(['8Core 6G 64G', '4Core 2G 32G'], $p->variants()->pluck('title')->all());
        $this->assertSame('210.00', $p->variants()->where('sku', 'AE-123-999')->firstOrFail()->price);
        $this->assertSame('200.00', $p->fresh()->price_min);
        $this->assertSame(['200.00', '210.00'], $p->variants()->orderBy('id')->pluck('price')->all());
        $this->getJson(route('supplier-variants.show', $p))->assertOk()->assertJsonPath('app.0.price', '200.00');
        $this->assertSame('old', $p->fresh()->meta['variant_recreation_history'][0]['variants'][0]['sku']);
    }

    public function test_recreate_with_no_eligible_offers_keeps_existing_variants_and_link(): void
    {
        $p = $this->product();
        $old = $p->variants()->create(['title' => 'Old', 'price' => 900]);
        $raw = ['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => [[
            'sku_id' => '997', 'sku_price' => '70.00', 'currency_code' => 'EUR',
            'ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [['sku_property_id' => 14, 'sku_property_value' => 'P1 AI AHDC6']]],
        ]]]];
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andReturn($raw));
        $this->postJson(route('supplier-variants.recreate', $p), ['source_url' => 'https://es.aliexpress.com/item/456.html'])->assertUnprocessable();
        $this->assertDatabaseHas('configurator_variants', ['id' => $old->id]);
        $this->assertSame('https://es.aliexpress.com/item/123.html', $p->fresh()->meta['source_url']);
    }

    public function test_recreate_keeps_existing_shopify_variant_identity_for_same_supplier_sku(): void
    {
        $p = $this->product();
        $old = $p->variants()->create(['title' => 'Old name', 'sku' => 'old', 'shopify_variant_id' => '98765', 'price' => 900,
            'meta' => ['source_product_id' => '123', 'source_sku_id' => '999']]);
        $raw = $this->response();
        $raw['ae_item_sku_info_dtos']['ae_item_sku_info_d_t_o'][0]['ae_sku_property_dtos']['ae_sku_property_d_t_o'][0]['sku_property_value'] = 'PX9 8Core 8GB 128GB';
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andReturn($raw));
        $this->postJson(route('supplier-variants.recreate', $p))->assertOk()->assertJsonPath('created', 1);
        $new = $p->variants()->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('98765', (string) $new->shopify_variant_id);
        $this->assertSame('210.00', $new->price);
    }

    public function test_recreate_is_blocked_for_cameras(): void
    {
        $p = $this->product(); $p->update(['category' => 'camera']);
        $this->postJson(route('supplier-variants.recreate', $p))->assertUnprocessable();
    }

    public function test_recreate_keeps_cheapest_offer_per_configuration(): void
    {
        $p = $this->product();
        $rows = [
            ['101', 'P1L (4 Núcleos 1G 16G) · Polonia', '90.00'],
            ['102', 'P1L (4 Núcleos 1G 16G) · España', '80.00'],
            ['103', 'QS6 (8 Núcleos 6GB 64GB) · Alemania', '120.00'],
            ['104', 'QS6 (8 Núcleos 6GB 64GB) · Francia', '110.00'],
            ['105', 'PX9 · Italia', '70.00'],
        ];
        $raw = ['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => array_map(fn ($r) => [
            'sku_id' => $r[0], 'sku_price' => $r[2], 'currency_code' => 'EUR',
            'ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [['sku_property_id' => 14, 'sku_property_value' => $r[1]]]],
        ], $rows)]];
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->once()->andReturn($raw));
        $this->postJson(route('supplier-variants.recreate', $p))->assertOk()->assertJsonPath('created', 2)->assertJsonPath('excluded', 3);
        $this->assertEqualsCanonicalizing(['102', '104'], $p->variants()->get()->map(fn ($v) => $v->meta['source_sku_id'])->all());
        $this->assertEqualsCanonicalizing(['4Core 1G 16G', '8Core 6G 64G'], $p->variants()->pluck('title')->all());
        $this->assertSame('190.00', $p->variants()->where('sku', 'AE-123-102')->firstOrFail()->price);
    }

    public function test_recreate_accepts_ram_rom_labels_and_replaces_existing_variants(): void
    {
        $p = $this->product();
        $old = $p->variants()->create(['title' => 'L494 4 pcs Bosch CD', 'price' => 2626.66]);
        $rows = [
            ['101', '8Core 4GB RAM 128GB ROM', '519.18'],
            ['102', '8Core 8GB RAM 128GB ROM', '535.44'],
            ['103', '4Core 2GB RAM 64GB ROM', '486.49'],
            ['104', '4Core 4GB RAM 64GB ROM', '480.00'],
            ['105', '8Core 4GB RAM 128GB ROM DVR', '540.00'],
            ['106', '8Core 4GB RAM 128GB ROM 360', '500.00'],
            ['107', '4GB RAM 128GB ROM', '510.00'],
            ['108', '4GB RAM 128GB ROM · España', '500.00'],
            ['109', 'SYNC2 128GB 8core · China Mainland', '490.00'],
        ];
        $raw = ['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => array_map(fn ($r) => [
            'sku_id' => $r[0], 'sku_price' => $r[2], 'currency_code' => 'EUR',
            'ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [['sku_property_id' => 14, 'sku_property_value' => $r[1]]]],
        ], $rows)]];
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->twice()->andReturn($raw));
        $this->getJson(route('supplier-variants.show', $p))->assertOk()
            ->assertJsonPath('aliexpress.4.recreate_name', '4G 128G')
            ->assertJsonPath('aliexpress.6.recreate_name', null);
        $this->postJson(route('supplier-variants.recreate', $p))->assertOk()->assertJsonPath('created', 5)->assertJsonPath('excluded', 4)->assertJsonCount(5, 'app');
        $this->assertDatabaseMissing('configurator_variants', ['id' => $old->id]);
        $this->assertEqualsCanonicalizing(['8Core 4G 128G', '8Core 8G 128G', '4Core 2G 64G', '4Core 4G 64G', '4G 128G'], $p->variants()->pluck('title')->all());
        $this->assertSame('108', $p->variants()->where('title', '4G 128G')->firstOrFail()->meta['source_sku_id']);
    }

    public function test_recreate_reads_supplier_memory_written_as_2_dash_32g(): void
    {
        $p = $this->product();
        $rows = [
            ['101', '2-32G no carplay', '85.58'],
            ['102', '2-32G carplay', '80.00'],
            ['103', '4-64G CAM 2K', '104.75'],
            ['104', 'SYNC2 128GB 8core', '90.00'],
        ];
        $raw = ['ae_item_sku_info_dtos' => ['ae_item_sku_info_d_t_o' => array_map(fn ($r) => [
            'sku_id' => $r[0], 'sku_price' => $r[2], 'currency_code' => 'EUR',
            'ae_sku_property_dtos' => ['ae_sku_property_d_t_o' => [['sku_property_id' => 14, 'sku_property_value' => $r[1]]]],
        ], $rows)]];
        $this->mock(AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->twice()->andReturn($raw));
        $this->getJson(route('supplier-variants.show', $p))->assertOk()
            ->assertJsonPath('aliexpress.0.recreate_name', '2G 32G')
            ->assertJsonPath('aliexpress.2.recreate_name', '4G 64G')
            ->assertJsonPath('aliexpress.3.recreate_name', null);
        $this->postJson(route('supplier-variants.recreate', $p))->assertOk()->assertJsonPath('created', 2);
        $this->assertSame('102', $p->variants()->where('title', '2G 32G')->firstOrFail()->meta['source_sku_id']);
        $this->assertNotNull($p->variants()->where('title', '4G 64G')->first());
    }

    public function test_recreate_reads_cores_spelled_in_supplier_language(): void
    {
        $updater = app(\App\Services\CatalogPriceUpdater::class);
        $this->assertSame('8Core 4G 64G', $updater->recreatedVariantName('4GB ram X 64GB rom · Ocho Núcleos'));
        $this->assertSame('4Core 2G 32G', $updater->recreatedVariantName('2GB RAM X 32GB ROM · Cuatro Núcleos'));
    }
}
