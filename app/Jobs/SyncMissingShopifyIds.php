<?php

namespace App\Jobs;

use App\Models\ConfiguratorProduct;
use App\Services\ShopifyIdSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SyncMissingShopifyIds implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1320;

    public int $tries = 1;

    public function __construct(public int $userId)
    {
        $this->onConnection('autogenesi')->onQueue('autogenesi');
    }

    public function handle(ShopifyIdSync $sync): void
    {
        $key = 'shopify-id-sync:'.$this->userId;
        $state = ['status' => 'running', 'total' => 0, 'checked' => 0, 'products' => 0, 'variants' => 0, 'missing' => 0, 'unmatched' => 0, 'errors' => 0];
        try {
            $query = ConfiguratorProduct::query()->where(fn ($q) => $q
                ->whereHas('variants', fn ($v) => $v->whereNull('shopify_variant_id'))
                ->orWhereNull('meta->shopify_product_id'));
            $state['total'] = $query->count();
            Cache::put($key, $state, now()->addDay());
            $query->with('variants')->orderBy('id')->chunkById(25, function ($products) use ($sync, $key, &$state) {
                foreach ($products as $product) {
                    try {
                        $result = $sync->sync($product);
                        $state['products'] += $result['status'] === 'updated' ? 1 : 0;
                        $state['variants'] += $result['variants'];
                        $state[$result['status'] === 'missing' ? 'missing' : ($result['status'] === 'error' ? 'errors' : 'unmatched')]
                            += in_array($result['status'], ['missing', 'error', 'unmatched'], true) ? 1 : 0;
                    } catch (\Throwable $e) {
                        report($e);
                        $state['errors']++;
                    }
                    $state['checked']++;
                    Cache::put($key, $state, now()->addDay());
                }
            });
            $state['status'] = 'finished';
        } catch (\Throwable $e) {
            report($e);
            $state['status'] = 'failed';
        }
        Cache::put($key, $state, now()->addDay());
    }

    public function failed(?\Throwable $e): void
    {
        $key = 'shopify-id-sync:'.$this->userId;
        Cache::put($key, [...(Cache::get($key) ?? []), 'status' => 'failed'], now()->addDay());
    }
}
