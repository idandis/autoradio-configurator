<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

class AutogenesiAliExpressApi
{
    public function call(int $user, string $method, array $args, bool $retried = false): array
    {
        if (! in_array($method, ['aliexpress.ds.text.search', 'aliexpress.ds.product.get'], true)) {
            throw new RuntimeException('Metodo API non consentito.');
        }
        $params = [
            'app_key' => (string) config('services.aliexpress.app_key'),
            'session' => app(AutogenesiAliExpressAuth::class)->accessToken($user),
            'timestamp' => (string) (int) floor(microtime(true) * 1000),
            'sign_method' => 'sha256', 'method' => $method, 'v' => '2.0', 'format' => 'json',
        ] + $args;
        ksort($params, SORT_STRING);
        $base = '';
        foreach ($params as $key => $value) { $base .= $key.$value; }
        $params['sign'] = strtoupper(hash_hmac('sha256', $base, config('services.aliexpress.app_secret')));
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(10)->timeout(35)->post('https://api-sg.aliexpress.com/sync', $params);
        } catch (Throwable) {
            $this->diagnostic($user, $method, 0, 'connection_error');
            throw new RuntimeException('AliExpress non raggiungibile. Riprova più tardi.');
        }
        app(ProductSheetProcessing::class)->check();
        $data = $response->json();
        $result = is_array($data) ? ($data[str_replace('.', '_', $method).'_response'] ?? null) : null;
        if (! $response->successful() || ! is_array($result) || isset($data['error_response'])
            || (isset($result['code']) && ! in_array((string) $result['code'], ['0', '00', '200'], true))
            || (isset($result['rsp_code']) && ! in_array((string) $result['rsp_code'], ['0', '00', '200'], true))) {
            $error = is_array($data) ? ($data['error_response'] ?? $result ?? []) : [];
            $code = $error['sub_code'] ?? $error['code'] ?? $error['rsp_code'] ?? 'invalid_response';
            $code = is_scalar($code) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', (string) $code) ? (string) $code : 'invalid_response';
            $this->diagnostic($user, $method, $response->status(), $code);
            $message = $error['sub_msg'] ?? $error['msg'] ?? $error['rsp_msg'] ?? '';
            if (is_string($message) && $message !== '') {
                $message = str_replace(array_filter([$params['session'], config('services.aliexpress.app_secret')]), '[redacted]', strip_tags($message));
                Storage::disk('local')->put('autogenesi/aliexpress/last-error-'.$user.'.json', json_encode([
                    'time' => now()->toIso8601String(), 'method' => $method, 'code' => $code, 'message' => mb_substr($message, 0, 500),
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            if (preg_match('/InvalidSession|InvalidAccessToken|IllegalAccessToken|ExpiredAccessToken|SessionExpired|TokenExpired/i', $code)) {
                app(AutogenesiAliExpressAuth::class)->invalidate($user, $params['session']);
                throw new RuntimeException('Autorizzazione AliExpress scaduta o non più valida. Premi Autorizza AliExpress e poi riprendi la lista.');
            }
            if ($code === 'NGSELECTION_SEARCH_ERROR' && $method === 'aliexpress.ds.text.search') {
                if (! $retried) {
                    Sleep::for(1)->seconds();
                    return $this->call($user, $method, $args, true);
                }
                throw new RuntimeException('Il servizio di ricerca AliExpress non ha completato la richiesta (NGSELECTION_SEARCH_ERROR), anche dopo un secondo tentativo. Attendi qualche minuto e riprova.');
            }
            throw new RuntimeException('Errore AliExpress '.$code.' (HTTP '.$response->status().'). Riprova; se persiste, segnala questo codice.');
        }
        $this->diagnostic($user, $method, $response->status(), 'ok');
        return $result;
    }

    private function diagnostic(int $user, string $method, int $http, string $code): void
    {
        Storage::disk('local')->put('autogenesi/aliexpress/last-api-'.$user.'.json', json_encode([
            'time' => now()->toIso8601String(), 'method' => $method, 'http' => $http, 'code' => $code,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function search(int $user, string $query, string $sort = 'best_match'): array
    {
        if (!in_array($sort, ['price', 'sales', 'best_match'], true)) throw new RuntimeException('Ordinamento API non valido.');
        app(AutogenesiAliExpressAuth::class)->accessToken($user);
        $query = preg_replace('/\s+/u', ' ', trim($query));
        $key = 'autogenesi.api-search.v3.'.hash('sha256', config('services.aliexpress.app_key').':'.$user.':'.$query.':'.$sort);
        if ($cached = Cache::get($key)) {
            return $cached + ['cached' => true];
        }
        $data = $this->call($user, 'aliexpress.ds.text.search', [
            'keyWord' => $query, 'local' => 'es_ES', 'currency' => 'EUR', 'countryCode' => 'ES', 'pageSize' => '20', 'pageIndex' => '1',
        ]);
        $items = data_get($data, 'data.products.selection_search_product', []);
        if (! is_array($items)) { throw new RuntimeException('Risposta ricerca AliExpress non valida.'); }
        if (isset($items['itemId'])) { $items = [$items]; }
        $products = [];
        foreach (array_slice($items, 0, 20) as $item) {
            $id = (string) ($item['itemId'] ?? '');
            if (! ctype_digit($id)) { continue; }
            $price = $item['targetSalePrice'] ?? null;
            $orders = (string) ($item['orders'] ?? '');
            $sales = null;
            if (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*([kKmM])\+?\s*$/', $orders, $m)) $sales = (int) ((float) str_replace(',', '.', $m[1]) * (strtolower($m[2]) === 'k' ? 1000 : 1000000));
            elseif (preg_match('/^[\d.,\s]+\+?$/', $orders)) $sales = (int) preg_replace('/\D/', '', $orders);
            $products[] = ['id' => $id, 'title' => (string) ($item['title'] ?? ''),
                'price' => is_numeric($price) ? (float) $price : null,
                'orders' => $orders, 'sales' => $sales,
                'url' => 'https://es.aliexpress.com/item/'.$id.'.html'];
        }
        if ($sort === 'price') usort($products, fn ($a, $b) => ($a['price'] ?? INF) <=> ($b['price'] ?? INF));
        if ($sort === 'sales') usort($products, fn ($a, $b) => ($b['sales'] ?? -1) <=> ($a['sales'] ?? -1));
        $result = ['query' => $query, 'sort_by' => $sort, 'products' => $products, 'total' => (int) data_get($data, 'data.totalCount', 0), 'searched_at' => now()->toIso8601String()];
        Cache::put($key, $result, now()->addMinutes(5));
        return $result + ['cached' => false];
    }

    public function product(int $user, string $id): array
    {
        if (! preg_match('/^[0-9]{1,30}$/D', $id)) { throw new RuntimeException('ID prodotto non valido.'); }
        $data = $this->call($user, 'aliexpress.ds.product.get', [
            'product_id' => $id, 'ship_to_country' => 'ES', 'target_currency' => 'EUR', 'target_language' => 'ES',
        ]);
        $result = $data['result'] ?? null;
        if (! is_array($result) || empty($result['ae_item_base_info_dto'])) {
            throw new RuntimeException('Scheda prodotto non disponibile tramite API.');
        }
        return $result;
    }
}
