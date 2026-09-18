<?php

use App\Services\ItalianPurchaseEmails;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('shared-configurations:prune')->daily();

Artisan::command('italian-orders:prepare-emails', function () {
    DB::table('italian_order_emails')->where('status', 'pending')->orderBy('id')->chunkById(100, function ($rows) {
        foreach ($rows as $row) {
            try {
                app(ItalianPurchaseEmails::class)->prepare($row->id);
            } catch (Throwable $exception) {
                // Keep this row pending, but do not block other customers' emails.
                report($exception);
                $this->warn('Email ordine '.$row->id.': invio non riuscito, verrà ritentato.');
            }
        }
    });
})->purpose('Deliver pending purchase emails or prepare test previews');

Schedule::command('italian-orders:prepare-emails')->everyMinute()->withoutOverlapping();
