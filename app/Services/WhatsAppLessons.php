<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WhatsAppLessons
{
    public function handles(array $messages): array
    {
        $url = config('services.shopify.storefront_url') ?: 'https://www.autoradiocanario.com';
        $host = preg_quote((string) parse_url($url, PHP_URL_HOST), '~');
        preg_match_all('~https?://'.$host.'/products/([^?\s)\]#]+)~ui', implode(' ', $messages), $m);

        return array_values(array_unique(array_map('rawurldecode', $m[1])));
    }

    public function applicable(string $phone, array $handles = []): array
    {
        if (! Schema::hasTable('whatsapp_lessons')) return [];

        return DB::table('whatsapp_lessons')->where('session', config('waha.session'))
            ->where(fn ($q) => $q->where('scope', 'general')
                ->orWhere(fn ($q) => $q->where('scope', 'client')->where('target', $phone))
                ->orWhere(fn ($q) => $q->where('scope', 'product')->whereIn('target', $handles)))
            ->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function prompt(string $phone, array $handles = []): string
    {
        $rows = array_slice($this->applicable($phone, $handles), 0, 20);
        if (! $rows) return '';

        return ' Insegnamenti approvati dall’amministratore: sono informazioni e correzioni confermate dal gestore, da usare insieme al catalogo per questo contesto. I più recenti prevalgono. Non estendere fatti di prodotto ad altri articoli: '
            .json_encode(array_map(fn ($r) => ['scope' => $r['scope'], 'target' => $r['scope'] === 'product' ? $r['target'] : '', 'rule' => $r['instruction']], $rows), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function answer(string $question, string $locale, string $phone, array $handles = []): ?string
    {
        $normalize = fn ($s) => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($s))));
        foreach ($this->applicable($phone, $handles) as $row) {
            if ($row['locale'] === $locale && filled($row['question']) && filled($row['answer'])
                && $normalize($row['question']) === $normalize($question)) return $row['answer'];
        }

        return null;
    }
}
