<?php

namespace App\Console\Commands;

use App\Services\AutogenesiRunControl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkAutogenesi extends Command
{
    protected $signature = 'autogenesi:work {--run-id=}';
    protected $description = 'Esegue la coda AutoGenesi locale e termina quando non ci sono richieste';

    public function handle(AutogenesiRunControl $control): int
    {
        if (!app()->environment('local')) return self::FAILURE;
        $runId = $this->option('run-id') ?: $control->state()['run_id'];
        $active = fn () => !$control->state()['stopped'] && $control->state()['run_id'] === $runId;
        if (!$active()) return self::SUCCESS;
        $disk = Storage::disk('local'); $disk->makeDirectory('autogenesi/browser');
        $lock = fopen($disk->path('autogenesi/browser/worker.lock'), 'c');
        if (!$lock) return self::FAILURE;
        $deadline = microtime(true) + 8;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (!$active() || microtime(true) >= $deadline) { fclose($lock); return self::FAILURE; }
            usleep(100000);
        }
        if (!$active()) { flock($lock, LOCK_UN); fclose($lock); return self::SUCCESS; }
        $pid = $disk->path('autogenesi/browser/worker.json');
        file_put_contents($pid, json_encode(['pid' => getmypid(), 'run_id' => $runId]), LOCK_EX); chmod($pid, 0600);
        try {
            $idle = 0;
            while ($active()) {
                if (!DB::table('jobs')->where('queue', 'autogenesi')->exists()) {
                    if (++$idle >= 10) break;
                    sleep(1); continue;
                }
                $idle = 0;
                $this->call('queue:work', ['connection' => 'autogenesi', '--queue' => 'autogenesi', '--once' => true,
                    '--timeout' => 1320, '--tries' => 1000, '--sleep' => 1]);
            }
        } finally {
            if (is_file($pid) && (json_decode(file_get_contents($pid), true)['pid'] ?? null) === getmypid()) unlink($pid);
            flock($lock, LOCK_UN); fclose($lock);
        }
        return self::SUCCESS;
    }
}
