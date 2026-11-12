<?php

namespace Tests\Feature;

use App\Models\ConfiguratorProduct;
use App\Models\User;
use App\Services\DsersLinkRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DsersLinkRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_by_id_then_exact_title_preserves_existing_links_and_collects_multiple_suppliers(): void
    {
        $a = ConfiguratorProduct::create(['handle' => 'a', 'category' => 'screen', 'title' => 'Radio español', 'meta' => ['shopify_product_id' => '123', 'keep' => true]]);
        $b = ConfiguratorProduct::create(['handle' => 'b', 'category' => 'camera', 'title' => 'Cámara trasera']);
        $c = ConfiguratorProduct::create(['handle' => 'c', 'category' => 'screen', 'title' => 'Radio existente', 'meta' => ['source_url' => 'https://example.com/original']]);
        $row = fn ($title, $id, $n) => ['title_es' => $title, 'shopify_product_id' => $id, 'supplier_url' => "https://www.aliexpress.com/item/$n.html"];
        $result = app(DsersLinkRecovery::class)->recover([$row('Nombre cambiado', '123', '1001'), $row('Nombre cambiado', '123', '1002'), $row(' CÁMARA  trasera ', null, '1003'), $row('Radio existente', null, '1004'), $row('Radio español', '999', '1005')]);
        $this->assertSame(['updated' => 2, 'existing' => 1, 'unmatched' => 1, 'ambiguous' => 0], $result);
        $this->assertSame('https://es.aliexpress.com/item/1001.html', $a->fresh()->meta['source_url']);
        $this->assertSame(['https://es.aliexpress.com/item/1002.html'], $a->fresh()->meta['additional_source_urls']);
        $this->assertTrue($a->fresh()->meta['keep']);
        $this->assertSame('https://es.aliexpress.com/item/1003.html', $b->fresh()->meta['source_url']);
        $this->assertSame('https://example.com/original', $c->fresh()->meta['source_url']);
    }

    public function test_ambiguous_titles_and_invalid_urls_do_not_change_products(): void
    {
        foreach (['a', 'b'] as $handle) ConfiguratorProduct::create(['handle' => $handle, 'category' => 'screen', 'title' => 'Radio']);
        $result = app(DsersLinkRecovery::class)->recover([
            ['title_es' => 'Radio', 'supplier_url' => 'https://es.aliexpress.com/item/123.html'],
            ['title_es' => 'Radio', 'supplier_url' => 'https://aliexpress.com.evil.test/item/123.html'],
        ]);
        $this->assertSame(1, $result['ambiguous']);
        $this->assertSame(1, $result['unmatched']);
        $this->assertSame(0, $result['updated']);
    }

    public function test_upload_is_local_and_admin_only_and_imports_valid_json(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))->post('/imported-products/recover-dsers')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post('/imported-products/recover-dsers')->assertForbidden();
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $p = ConfiguratorProduct::create(['handle' => 'a', 'category' => 'screen', 'title' => 'Radio']);
        $file = UploadedFile::fake()->createWithContent('dsers.json', json_encode(['format' => 'autogenesi.dsers.v1', 'rows' => [['title_es' => 'Radio', 'supplier_url' => 'https://es.aliexpress.com/item/123.html']]]));
        $this->post('/imported-products/recover-dsers', ['dsers_file' => $file])->assertRedirect()->assertSessionHas('status');
        $this->assertSame('https://es.aliexpress.com/item/123.html', $p->fresh()->meta['source_url']);
    }
}
