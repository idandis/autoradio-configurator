<?php

namespace App\Jobs;

use App\Models\ConfiguratorProduct;
use App\Models\ProductPriceUpdate;
use App\Services\AutogenesiAliExpressApi;
use App\Services\AutogenesiRunControl;
use App\Services\CatalogPriceUpdater;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class UpdateCatalogPrices implements ShouldQueue
{
    use Queueable;

    public int $tries = 1000;
    public int $timeout = 240;
    public bool $failOnTimeout = true;
    public string|null $controlId;

    public function __construct(public int $runId, public int $generation)
    {
        $this->controlId = app(AutogenesiRunControl::class)->state()['run_id'];
        $this->onConnection('autogenesi')->onQueue('autogenesi');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('catalog-price-update'))->releaseAfter(10)->expireAfter(260)];
    }

    private function active(ProductPriceUpdate $run): bool
    {
        $state = app(AutogenesiRunControl::class)->state();
        return app()->environment('local') && $run->status === 'running' && $run->generation === $this->generation
            && !$state['stopped'] && $state['run_id'] === $this->controlId;
    }

    public function handle(AutogenesiAliExpressApi $api, CatalogPriceUpdater $updater): void
    {
        $run = ProductPriceUpdate::find($this->runId);
        if (!$run || !$this->active($run)) return;
        $items = $run->items; $index = null;
        foreach ($items as $i => $item) if (in_array($item['status'], ['pending', 'running'], true)) { $index = $i; break; }
        if ($index === null) { $run->update(['status' => 'completed']); return; }
        $items[$index]['status'] = 'running'; $run->update(['items' => $items]);
        try {
            $p = ConfiguratorProduct::findOrFail($items[$index]['id']);
            if (!$updater->isRadio($p)) throw new \RuntimeException('Categoria esclusa: la regola prezzo si applica soltanto alle autoradio.');
            $sources = $updater->sources($p);
            if (!$sources) throw new \RuntimeException('Nessun link AliExpress valido.');
            if (count($sources) > 3) throw new \RuntimeException('Più di tre fonti: selezionare le fonti della variante prima di aggiornare.');
            $offers = [];
            foreach ($sources as $source) {
                if (!$this->active($run->fresh())) return;
                $offers = [...$offers, ...$updater->offers($api->product($run->user_id, $source), $source)];
            }
            $result = $updater->apply($p->id, $offers, function () {
                $run = ProductPriceUpdate::lockForUpdate()->findOrFail($this->runId);
                if (!$this->active($run)) throw new \RuntimeException('Aggiornamento interrotto.');
            });
            $items[$index]['status'] = 'completed'; $items[$index]['result'] = $result;
        } catch (\Throwable $e) {
            $items[$index]['status'] = 'error';
            $items[$index]['error'] = $e instanceof \RuntimeException ? mb_substr($e->getMessage(), 0, 500) : 'Errore durante la lettura del costo. Nessun abbinamento inventato.';
            if (preg_match('/Autorizzazione|autorizza|token|accesso.*AliExpress/i', $items[$index]['error'])) {
                $fresh = $run->fresh();
                if ($this->active($fresh)) $fresh->update(['items' => $items, 'status' => 'blocked', 'error' => $items[$index]['error']]);
                return;
            }
        }
        DB::transaction(function () use ($items) {
            $run = ProductPriceUpdate::lockForUpdate()->findOrFail($this->runId);
            if (!$this->active($run)) return;
            $pending = collect($items)->contains(fn ($i) => $i['status'] === 'pending');
            $run->update(['items' => $items, 'status' => $pending ? 'running' : 'completed']);
            if ($pending) self::dispatch($run->id, $run->generation)->delay(now()->addSeconds(3))->afterCommit();
        });
    }

    public function failed(?\Throwable $e): void
    {
        $run = ProductPriceUpdate::find($this->runId);
        if ($run && $this->active($run)) $run->update(['status' => 'blocked', 'error' => 'Processo interrotto o tempo massimo superato. Premi Riprendi.']);
    }
}
