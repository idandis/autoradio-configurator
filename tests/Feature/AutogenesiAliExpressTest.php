<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;
use App\Services\AutogenesiAliExpressAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

class AutogenesiAliExpressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->detectEnvironment(fn () => 'local');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        config(['services.aliexpress.app_key' => 'test-key', 'services.aliexpress.app_secret' => 'test-secret']);
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['api-sg.aliexpress.com/rest/auth/token/create' => Http::response(['access_token' => 'private-token', 'refresh_token' => 'private-refresh', 'expires_in' => 3600])]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_callback_is_ready_without_credentials_and_never_public(): void
    {
        config(['services.aliexpress.app_key' => null]);
        $this->get('/autogenesi/aliexpress/callback')->assertOk()->assertSee('Callback pronto');
        $this->post('/autogenesi/aliexpress/authorize')->assertStatus(503);
        app()->detectEnvironment(fn () => 'production');
        $this->get('/autogenesi/aliexpress/callback')->assertForbidden();
    }

    public function test_authorization_limit_is_independent_of_product_requests_and_has_retry_header(): void
    {
        $this->mock(\App\Services\AutogenesiAliExpressApi::class, fn ($m) => $m->shouldReceive('product')->times(5)->andReturn(['ae_item_base_info_dto' => ['subject' => 'Radio']]));
        for ($i = 0; $i < 5; $i++) $this->postJson('/autogenesi/aliexpress/product', ['product_id' => '123'])->assertOk();
        for ($i = 0; $i < 5; $i++) $this->postJson('/autogenesi/aliexpress/authorize')->assertOk()->assertJsonStructure(['url']);
        $this->postJson('/autogenesi/aliexpress/authorize')->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/autogenesi/aliexpress/authorize')->assertOk();
    }

    public function test_authorization_checks_state_and_stores_encrypted_code_once(): void
    {
        $r = $this->post('/autogenesi/aliexpress/authorize')->assertRedirect();
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame(config('services.aliexpress.redirect_uri'), $q['redirect_uri']);
        $url = '/autogenesi/aliexpress/callback?'.http_build_query(['state' => $q['state'], 'code' => 'private-code']);
        $this->get($url)->assertRedirect(route('autogenesi.aliexpress.callback'));
        $this->assertNull(Cache::get('autogenesi.aliexpress.code.'.auth()->id()));
        $this->assertTrue(app(AutogenesiAliExpressAuth::class)->status(auth()->id())['connected']);
        $file = Storage::disk('local')->allFiles('autogenesi/aliexpress')[0];
        $value = Storage::disk('local')->get($file);
        $this->assertStringNotContainsString('private-token', $value);
        $this->assertSame('private-token', json_decode(Crypt::decryptString($value), true)['access_token']);
        Http::assertSent(function ($r) {
            $p = $r->data();
            $sign = $p['sign'];
            unset($p['sign']);
            ksort($p);
            $base = '/auth/token/create';
            foreach ($p as $k => $v) { $base .= $k.$v; }
            return $sign === strtoupper(hash_hmac('sha256', $base, 'test-secret')) && !isset($p['app_secret']);
        });
        $this->get($url)->assertForbidden();
    }

    public function test_expired_access_token_renews_from_saved_refresh_token(): void
    {
        Cache::put('autogenesi.aliexpress.code.'.auth()->id(), Crypt::encryptString('code'), 60);
        app(AutogenesiAliExpressAuth::class)->exchangePending(auth()->id());
        $disk = Storage::disk('local');
        $file = $disk->allFiles('autogenesi/aliexpress')[0];
        $old = json_decode(Crypt::decryptString($disk->get($file)), true);
        $old['expires_at'] = time() - 1;
        $old['refresh_expires_at'] = time() + 3600;
        $disk->put($file, Crypt::encryptString(json_encode($old)));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api-sg.aliexpress.com/rest/auth/token/refresh' => Http::response([
            'access_token' => 'renewed-token', 'refresh_token' => 'renewed-refresh',
            'expires_in' => 3600, 'refresh_expires_in' => 1800,
        ])]);
        $this->assertSame('renewed-token', app(AutogenesiAliExpressAuth::class)->accessToken(auth()->id()));
        $this->assertTrue(app(AutogenesiAliExpressAuth::class)->status(auth()->id())['connected']);
        $this->assertSame('renewed-refresh', json_decode(Crypt::decryptString($disk->get($file)), true)['refresh_token']);
        Http::assertSentCount(1);
    }

    public function test_status_renews_access_token_before_expiry(): void
    {
        Cache::put('autogenesi.aliexpress.code.'.auth()->id(), Crypt::encryptString('code'), 60);
        app(AutogenesiAliExpressAuth::class)->exchangePending(auth()->id());
        $disk = Storage::disk('local');
        $file = $disk->allFiles('autogenesi/aliexpress')[0];
        $old = json_decode(Crypt::decryptString($disk->get($file)), true);
        $old['expires_at'] = time() + 300;
        $old['refresh_expires_at'] = time() + 3600;
        $disk->put($file, Crypt::encryptString(json_encode($old)));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api-sg.aliexpress.com/rest/auth/token/refresh' => Http::response([
            'access_token' => 'renewed-token', 'refresh_token' => 'renewed-refresh',
            'expires_in' => 3600, 'refresh_expires_in' => 1800,
        ])]);
        $this->getJson('/autogenesi/aliexpress/status')->assertOk()->assertJsonPath('connected', true);
        $this->assertSame('renewed-token', app(AutogenesiAliExpressAuth::class)->accessToken(auth()->id()));
        Http::assertSentCount(1);
    }

    public function test_invalid_state_and_non_admin_are_rejected(): void
    {
        $this->get('/autogenesi/aliexpress/callback?state=wrong&code=secret')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/autogenesi/aliexpress/callback')->assertForbidden();
    }

    public function test_public_bridge_on_production_forwards_only_to_fixed_local_callback(): void
    {
        app()->detectEnvironment(fn () => 'production');
        auth()->logout();
        $this->get('/autogenesi/aliexpress/relay')->assertOk()->assertSee('Ponte AutoGenesi pronto');
        $q = ['state' => str_repeat('a', 64), 'code' => 'private+code&value'];
        $r = $this->get('/autogenesi/aliexpress/relay?'.http_build_query($q + ['redirect_uri' => 'https://example.com']))
            ->assertRedirect('http://localhost:8000/autogenesi/aliexpress/callback?'.http_build_query($q, '', '&', PHP_QUERY_RFC3986))
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame('', $r->getContent());
        $this->get('/autogenesi/aliexpress/relay?state[]=bad&code=secret')->assertStatus(400);
        $this->get('/autogenesi/aliexpress/relay?state='.str_repeat('a', 64).'&code[]=bad')->assertStatus(400);
    }

    public function test_callback_accepts_code_relayed_from_public_redirect(): void
    {
        $r = $this->post('/autogenesi/aliexpress/authorize');
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $q);
        $bridge = $this->get('/autogenesi/aliexpress/relay?'.http_build_query(['state' => $q['state'], 'code' => 'bridge-code']));
        $this->get($bridge->headers->get('Location'))->assertRedirect(route('autogenesi.aliexpress.callback'));
        $this->assertTrue(app(AutogenesiAliExpressAuth::class)->status(auth()->id())['connected']);
    }

    public function test_failed_exchange_does_not_expose_provider_response_or_save_token(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api-sg.aliexpress.com/rest/auth/token/create' => Http::response(['code' => 'InvalidCode', 'message' => 'private-code test-secret'])]);
        Cache::put('autogenesi.aliexpress.code.'.auth()->id(), Crypt::encryptString('private-code'), 60);
        $this->post('/autogenesi/aliexpress/exchange')->assertSessionHas('aliexpress_token_error', 'AliExpress non ha rilasciato il token (InvalidCode). Ripeti l’autorizzazione.');
        $this->assertFalse(app(AutogenesiAliExpressAuth::class)->status(auth()->id())['connected']);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_api_search_uses_token_and_eur_target_price_without_browser(): void
    {
        Cache::put('autogenesi.aliexpress.code.'.auth()->id(), Crypt::encryptString('code'), 60);
        app(AutogenesiAliExpressAuth::class)->exchangePending(auth()->id());
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api-sg.aliexpress.com/sync' => Http::response(['aliexpress_ds_text_search_response' => ['code' => '00', 'data' => ['totalCount' => 1, 'products' => ['selection_search_product' => [
            ['itemId' => '12345', 'title' => 'Radio Kona', 'salePrice' => '100', 'salePriceCurrency' => 'CNY', 'targetSalePrice' => '13.00', 'orders' => '1.2k+'],
        ]]]]])]);
        $this->post('/autogenesi/aliexpress/search', ['query' => 'radio Hyundai Kona 2017 2020'])->assertRedirect()->assertSessionHas('aliexpress_search.products.0.price', 13.0);
        $this->postJson('/autogenesi/aliexpress/search', ['query' => 'radio Hyundai Kona 2017 2020'])->assertOk()->assertJsonPath('products.0.price', 13)->assertJsonPath('products.0.id', '12345');
        $this->assertSame(1200, app(\App\Services\AutogenesiAliExpressApi::class)->search(auth()->id(), 'radio Hyundai Kona 2017 2020')['products'][0]['sales']);
        Http::assertSent(fn ($r) => $r['session'] === 'private-token' && $r['currency'] === 'EUR' && $r['keyWord'] === 'radio Hyundai Kona 2017 2020' && !isset($r['app_secret']));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api-sg.aliexpress.com/sync' => Http::response(['error_response' => ['code' => '15', 'sub_code' => 'SystemError', 'msg' => 'private-token test-secret']])]);
        $this->postJson('/autogenesi/aliexpress/search', ['query' => 'radio seat ibiza 2018'])->assertStatus(422)->assertJsonPath('message', 'Errore AliExpress SystemError (HTTP 200). Riprova; se persiste, segnala questo codice.');
        $log = Storage::disk('local')->get('autogenesi/aliexpress/last-api-'.auth()->id().'.json');
        $this->assertStringContainsString('SystemError', $log);
        $this->assertStringNotContainsString('private-token', $log);
    }

    public function test_api_failure_is_safe_and_expired_token_stops_calls(): void
    {
        $this->post('/autogenesi/aliexpress/search', ['query' => 'radio Kona'])->assertSessionHas('aliexpress_token_error');
        $this->postJson('/autogenesi/aliexpress/search', ['query' => 'radio Kona'])->assertStatus(422)->assertJsonStructure(['message']);
        Http::assertNothingSent();
        $this->post('/autogenesi/aliexpress/product', ['product_id' => 'https://invalid'])->assertSessionHasErrors('product_id');
    }

    public function test_search_recovers_once_from_provider_failure_and_caches_success(): void
    {
        Cache::put('autogenesi.aliexpress.code.'.auth()->id(), Crypt::encryptString('code'), 60);
        app(AutogenesiAliExpressAuth::class)->exchangePending(auth()->id());
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api-sg.aliexpress.com/sync' => Http::sequence()
            ->push(['aliexpress_ds_text_search_response' => ['code' => 'NGSELECTION_SEARCH_ERROR']])
            ->push(['aliexpress_ds_text_search_response' => ['code' => '00', 'data' => ['totalCount' => 1, 'products' => ['selection_search_product' => [['itemId' => '123', 'title' => 'Radio Seat Ibiza 2018', 'targetSalePrice' => '99']]]]]])]);
        $this->postJson('/autogenesi/aliexpress/search', ['query' => 'radio seat ibiza 2018'])->assertOk()->assertJsonPath('products.0.id', '123')->assertJsonPath('cached', false);
        $this->postJson('/autogenesi/aliexpress/search', ['query' => 'radio seat ibiza 2018'])->assertOk()->assertJsonPath('cached', true);
        Http::assertSentCount(2);
    }

    public function test_revoked_token_requires_new_authorization_without_browser_fallback(): void
    {
        Cache::put('autogenesi.aliexpress.code.'.auth()->id(), Crypt::encryptString('code'), 60);
        app(AutogenesiAliExpressAuth::class)->exchangePending(auth()->id());
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api-sg.aliexpress.com/sync' => Http::response(['error_response' => ['sub_code' => 'InvalidSession']])]);
        $this->postJson('/autogenesi/aliexpress/search', ['query' => 'radio seat ibiza 2018'])->assertStatus(422);
        $this->assertFalse(app(AutogenesiAliExpressAuth::class)->status(auth()->id())['connected']);
        Http::assertSentCount(1);
    }

    public function test_three_search_orders_have_separate_cache_and_preserve_provider_relevance(): void
    {
        Cache::put('autogenesi.aliexpress.code.'.auth()->id(), Crypt::encryptString('code'), 60);
        app(AutogenesiAliExpressAuth::class)->exchangePending(auth()->id());
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api-sg.aliexpress.com/sync' => Http::response(['aliexpress_ds_text_search_response' => ['code' => '00', 'data' => ['totalCount' => 3, 'products' => ['selection_search_product' => [
            ['itemId' => '1', 'title' => 'First', 'targetSalePrice' => '50', 'orders' => '20'],
            ['itemId' => '2', 'title' => 'Cheapest', 'targetSalePrice' => '10', 'orders' => '10'],
            ['itemId' => '3', 'title' => 'Most sold', 'targetSalePrice' => '100', 'orders' => '1000+'],
        ]]]]])]);
        foreach (['price' => '2', 'sales' => '3', 'best_match' => '1'] as $sort => $first) {
            $this->postJson('/autogenesi/aliexpress/search', ['query' => 'radio Toyota', 'sort_by' => $sort])->assertOk()->assertJsonPath('products.0.id', $first)->assertJsonPath('sort_by', $sort);
        }
        Http::assertSentCount(3);
    }

    public function test_persistent_provider_failure_stops_after_two_calls(): void
    {
        Cache::put('autogenesi.aliexpress.code.'.auth()->id(), Crypt::encryptString('code'), 60);
        app(AutogenesiAliExpressAuth::class)->exchangePending(auth()->id());
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api-sg.aliexpress.com/sync' => Http::response(['aliexpress_ds_text_search_response' => ['code' => 'NGSELECTION_SEARCH_ERROR']])]);
        $r = $this->postJson('/autogenesi/aliexpress/search', ['query' => 'radio seat ibiza 2018'])->assertStatus(422);
        $this->assertStringContainsString('anche dopo un secondo tentativo', $r->json('message'));
        Http::assertSentCount(2);
    }
}
