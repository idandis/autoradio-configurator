<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extra_eu_visitors', function (Blueprint $table) {
            $table->boolean('is_bot')->default(false)->index()->after('user_agent');
            $table->string('bot_reason')->nullable()->after('is_bot');
            $table->boolean('bot_blocked')->default(false)->after('bot_reason');
        });

        DB::table('extra_eu_visitors')
            ->select(['id', 'user_agent', 'browser_language', 'referrer'])
            ->orderBy('id')
            ->chunkById(200, function ($visitors): void {
                foreach ($visitors as $visitor) {
                    $reason = $this->historicalBotReason(
                        (string) $visitor->user_agent,
                        $visitor->browser_language,
                        $visitor->referrer,
                    );

                    if ($reason !== null) {
                        DB::table('extra_eu_visitors')->where('id', $visitor->id)->update([
                            'is_bot' => true,
                            'bot_reason' => $reason,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('extra_eu_visitors', function (Blueprint $table) {
            $table->dropIndex(['is_bot']);
            $table->dropColumn(['is_bot', 'bot_reason', 'bot_blocked']);
        });
    }

    private function historicalBotReason(string $userAgent, ?string $language, ?string $referrer): ?string
    {
        if (trim($userAgent) === '') {
            return 'User agent assente';
        }

        if (preg_match('/(?:googlebot|google-inspectiontool|bingbot|duckduckbot|yandexbot|baiduspider|applebot)/i', $userAgent)) {
            return 'Motore di ricerca';
        }

        if (preg_match('/(?:bot|crawler|spider|slurp|headless|lighthouse|pagespeed|uptime|monitoring|healthcheck|curl|wget|python-requests|guzzlehttp|go-http-client|postmanruntime|semrush|ahrefs|mj12bot|bytespider|petalbot|claudebot|gptbot)/i', $userAgent)) {
            return 'Agente automatico dichiarato';
        }

        if (preg_match('/Android\s+([0-4])(?:\.|;|\s)/i', $userAgent)) {
            return 'Browser obsoleto o simulato';
        }

        return blank($language) && blank($referrer)
            ? 'Richiesta senza segnali browser'
            : null;
    }
};
