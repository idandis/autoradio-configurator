<?php

namespace App\Jobs;

use App\Models\AutogenesiBatch;
use App\Models\AutogenesiBatchItem;
use App\Services\AutogenesiExport;
use App\Services\AutogenesiImport;
use App\Services\AutogenesiSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class ProcessAutogenesiBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1000;

    public int $timeout = 1320;

    public bool $failOnTimeout = true;

    public ?int $itemId = null;

    public ?string $runId = null;

    public function __construct(public int $batchId, ?int $itemId = null)
    {
        $this->itemId = $itemId;
        $this->runId = app(\App\Services\AutogenesiRunControl::class)->state()['run_id'];
        $this->onConnection('autogenesi')->onQueue('autogenesi');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('autogenesi-browser:'.($this->runId ?? 'legacy')))->shared()->releaseAfter(15)->expireAfter(1400)];
    }

    public function handle(AutogenesiSearch $search, AutogenesiImport $importer, AutogenesiExport $exporter): void
    {
        if (!$this->active()) return;
        $batch = AutogenesiBatch::find($this->batchId);
        if (! $batch || ! in_array($batch->status, $this->itemId ? ['queued_single', 'running_single'] : ['queued', 'running'], true)) {
            return;
        }
        if (! app()->environment('local')) {
            $batch->update(['status' => 'blocked']);

            return;
        }
        $item = DB::transaction(function () use ($batch) {
            $current = AutogenesiBatch::lockForUpdate()->find($batch->id);
            if (! $current || ! in_array($current->status, $this->itemId ? ['queued_single', 'running_single'] : ['queued', 'running'], true)) {
                return null;
            }
            $current->items()->when($this->itemId, fn ($q) => $q->whereKey($this->itemId))->whereIn('status', ['searching', 'importing'])->update(['status' => 'failed', 'error' => 'Elaborazione precedente interrotta. Puoi riprovare la riga.', 'finished_at' => now()]);
            $item = $current->items()->when($this->itemId, fn ($q) => $q->whereKey($this->itemId))->where('status', 'queued')->lockForUpdate()->first();
            if ($item) {
                $current->update(['status' => $this->itemId ? 'running_single' : 'running']);
                $item->update(['status' => 'searching', 'error' => null, 'started_at' => now()]);
            }

            return $item;
        });
        if (! $item) {
            $this->next();

            return;
        }
        try {
            if (($batch->engine ?? 'browser') === 'api') {
                $p = app(\App\Services\AutogenesiApiImport::class)->importBest((int) $batch->user_id, $item->query, $item->vehicle,
                    function (string $url) use ($item) {
                        if (!$this->active()) throw new \RuntimeException('Importazione fermata manualmente.');
                        $item->update(['status' => 'importing', 'source_url' => $url]);
                    }, $batch->api_options ?? []);
                if (!$this->active()) return;
                if (!$p) {
                    $item->update(['status' => 'not_found', 'error' => 'Nessuna autoradio completa specifica con variante valida trovata nei candidati API analizzati.', 'finished_at' => now()]);
                } else {
                    $exporter->writeFiles($p);
                    $item->update(['status' => 'completed', 'source_url' => $p->source_url, 'product_id' => $p->id, 'error' => null, 'finished_at' => now()]);
                }
                $this->next();
                return;
            }
            $result = $search->search($item->query, 'best_price');
            if (! $this->active()) {
                return;
            }
            $url = $result['selected']['source_url'] ?? null;
            if (! $url) {
                $item->update(['status' => 'not_found', 'error' => 'Nessun prodotto specifico verificato nelle pagine analizzate.', 'finished_at' => now()]);
            } else {
                $item->update(['status' => 'importing', 'source_url' => $url]);
                $p = $importer->capture($url, $item->vehicle);
                if ($p->status !== 'draft') {
                    throw new \RuntimeException('Il prodotto esistente non è una bozza.');
                }
                if (! $this->active()) {
                    return;
                }
                $exporter->writeFiles($p);
                $item->update(['status' => 'completed', 'product_id' => $p->id, 'error' => null, 'finished_at' => now()]);
            }
        } catch (\Throwable $e) {
            if (! $this->active()) {
                return;
            }
            report($e);
            if (($batch->engine ?? 'browser') === 'api') {
                $auth = preg_match('/autorizzazione.*(?:scaduta|assente)|InvalidSession|InvalidAccessToken|IllegalAccessToken/i', $e->getMessage());
                $item->update(['status' => $auth ? 'blocked' : 'failed', 'finished_at' => now(),
                    'error' => $e instanceof \RuntimeException ? mb_substr($e->getMessage(), 0, 1000) : 'Importazione API non completata. Consulta il log.']);
                if ($auth) AutogenesiBatch::whereKey($this->batchId)->update(['status' => 'blocked']);
                $this->next();
                return;
            }
            $traffic = str_contains($e->getMessage(), 'Network traffic blocked');
            $blocked = $traffic || str_contains($e->getMessage(), 'Verification required');
            $item->update(['status' => $blocked ? 'blocked' : 'failed', 'finished_at' => now(),
                'error' => $traffic ? 'AliExpress ha rilevato traffico insolito. Lista fermata: riprova più tardi manualmente.' : ($blocked ? 'AliExpress richiede una verifica umana. Lista interrotta.' : 'Operazione non completata. Puoi riprovare questa riga.')]);
            if ($blocked) {
                AutogenesiBatch::whereKey($this->batchId)->update(['status' => 'blocked']);
            }
        }
        $this->next();
    }

    public function failed(?\Throwable $e): void
    {
        if (! $this->active()) {
            return;
        }
        AutogenesiBatchItem::where('batch_id', $this->batchId)->when($this->itemId, fn ($q) => $q->whereKey($this->itemId))->whereIn('status', ['searching', 'importing'])
            ->update(['status' => 'failed', 'error' => 'Processo interrotto o tempo massimo superato.', 'finished_at' => now()]);
        $this->next();
    }

    private function active(): bool
    {
        $state = app(\App\Services\AutogenesiRunControl::class)->state();
        return !$state['stopped'] && $state['run_id'] === $this->runId
            && in_array(AutogenesiBatch::find($this->batchId)?->status, $this->itemId ? ['queued_single', 'running_single'] : ['queued', 'running'], true);
    }

    private function next(): void
    {
        if (!$this->active()) return;
        $batch = AutogenesiBatch::find($this->batchId);
        if (! $batch || $batch->status === 'blocked') {
            return;
        }
        if ($this->itemId) {
            if (in_array($batch->status, ['queued_single', 'running_single'], true)) {
                $batch->update(['status' => $batch->items()->where('status', '!=', 'completed')->exists() ? 'paused' : 'completed']);
            }

            return;
        }
        if (! in_array($batch->status, ['queued', 'running'], true)) {
            return;
        }
        if ($batch->items()->where('status', 'queued')->exists()) {
            self::dispatch($this->batchId)->delay(now()->addSeconds(5));
        } else {
            $batch->update(['status' => $batch->items()->where('status', '!=', 'completed')->exists() ? 'completed_with_errors' : 'completed']);
        }
    }
}
