<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use App\Services\AutogenesiAliExpressAuth;
use RuntimeException;
use App\Services\AutogenesiAliExpressApi;

class AutogenesiAliExpressController extends Controller
{
    public function status(Request $request)
    {
        abort_unless(app()->environment('local'), 403);
        return response()->json(app(AutogenesiAliExpressAuth::class)->status($request->user()->id))
            ->header('Cache-Control', 'no-store');
    }

    public function authorize(Request $request)
    {
        abort_unless(app()->environment('local'), 403);
        abort_unless(config('services.aliexpress.app_key') && config('services.aliexpress.app_secret'), 503, 'Configura App Key e App Secret nel file .env.');
        $state = Str::random(64);
        $request->session()->put('aliexpress.oauth', [
            'hash' => hash('sha256', $state),
            'expires' => now()->addMinutes(10)->timestamp,
            'user' => $request->user()->id,
            'redirect' => config('services.aliexpress.redirect_uri'),
        ]);

        $url = 'https://api-sg.aliexpress.com/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'force_auth' => 'true',
            'client_id' => config('services.aliexpress.app_key'),
            'redirect_uri' => config('services.aliexpress.redirect_uri'),
            'state' => $state,
        ]);
        if ($request->expectsJson()) { return response()->json(['url' => $url])->header('Cache-Control', 'no-store'); }
        return redirect()->away($url);
    }

    public function callback(Request $request)
    {
        abort_unless(app()->environment('local'), 403);
        $received = false;
        if ($request->hasAny(['code', 'state', 'error'])) {
            $data = $request->validate([
                'state' => 'required|string|max:128',
                'code' => 'nullable|string|max:4096',
                'error' => 'nullable|string|max:256',
            ]);
            $oauth = $request->session()->pull('aliexpress.oauth');
            abort_unless($oauth && $oauth['expires'] >= now()->timestamp
                && $oauth['user'] === $request->user()->id
                && $oauth['redirect'] === config('services.aliexpress.redirect_uri')
                && hash_equals($oauth['hash'], hash('sha256', $data['state'])), 403, 'Autorizzazione scaduta o non valida. Riprova.');
            if (!empty($data['error']) || empty($data['code'])) {
                return redirect()->route('autogenesi.aliexpress.callback')->with('aliexpress_error', true);
            }
            Cache::put('autogenesi.aliexpress.code.'.$request->user()->id, Crypt::encryptString($data['code']), now()->addMinutes(10));
            return $this->exchange($request);
        }

        return response()->view('autogenesi.aliexpress', [
            'ready' => (bool) config('services.aliexpress.app_key'),
            'received' => $request->session()->get('aliexpress_received', $received),
            'failed' => $request->session()->get('aliexpress_error', false),
            'callback' => config('services.aliexpress.redirect_uri'),
            'connection' => app(AutogenesiAliExpressAuth::class)->status($request->user()->id),
            'pending' => Cache::has('autogenesi.aliexpress.code.'.$request->user()->id),
            'tokenError' => $request->session()->get('aliexpress_token_error'),
            'apiSearch' => $request->session()->get('aliexpress_search'),
            'apiProduct' => $request->session()->get('aliexpress_product'),
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function exchange(Request $request)
    {
        abort_unless(app()->environment('local'), 403);
        try {
            app(AutogenesiAliExpressAuth::class)->exchangePending($request->user()->id);
            return redirect()->route('autogenesi.aliexpress.callback')->with('aliexpress_received', true);
        } catch (RuntimeException $e) {
            return redirect()->route('autogenesi.aliexpress.callback')->with('aliexpress_token_error', $e->getMessage());
        }
    }

    public function search(Request $request, AutogenesiAliExpressApi $api)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['query' => 'required|string|max:150', 'sort_by' => 'sometimes|in:price,sales,best_match']);
        try {
            $result = $api->search($request->user()->id, $data['query'], $data['sort_by'] ?? 'best_match');
            if ($request->expectsJson()) { return response()->json($result)->header('Cache-Control', 'no-store'); }
            return redirect()->route('autogenesi.aliexpress.callback')->with('aliexpress_search', $result);
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) { return response()->json(['message' => $e->getMessage()], 422); }
            return redirect()->route('autogenesi.aliexpress.callback')->with('aliexpress_token_error', $e->getMessage());
        }
    }

    public function product(Request $request, AutogenesiAliExpressApi $api)
    {
        abort_unless(app()->environment('local'), 403);
        $data = $request->validate(['product_id' => 'required|string|regex:/^[0-9]{1,30}$/D']);
        try {
            $p = $api->product($request->user()->id, $data['product_id']);
            $variants = data_get($p, 'ae_item_sku_info_dtos.ae_item_sku_info_d_t_o', []);
            if (isset($variants['sku_id'])) { $variants = [$variants]; }
            $result = [
                'title' => data_get($p, 'ae_item_base_info_dto.subject'),
                'description' => mb_substr(strip_tags(data_get($p, 'ae_item_base_info_dto.detail', '')), 0, 1500),
                'url' => 'https://es.aliexpress.com/item/'.$data['product_id'].'.html',
                'variants' => array_map(fn ($v) => ['label' => $v['sku_attr'] ?? $v['sku_id'] ?? '', 'price' => $v['offer_sale_price'] ?? $v['sku_price'] ?? '', 'currency' => $v['currency_code'] ?? ''], array_slice($variants, 0, 20)),
            ];
            if ($request->expectsJson()) { return response()->json($result)->header('Cache-Control', 'no-store'); }
            return redirect()->route('autogenesi.aliexpress.callback')->with('aliexpress_product', $result);
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) { return response()->json(['message' => $e->getMessage()], 422); }
            return redirect()->route('autogenesi.aliexpress.callback')->with('aliexpress_token_error', $e->getMessage());
        }
    }
}
