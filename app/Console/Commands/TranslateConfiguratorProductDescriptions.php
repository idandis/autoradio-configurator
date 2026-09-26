<?php

namespace App\Console\Commands;

use App\Models\ConfiguratorProduct;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TranslateConfiguratorProductDescriptions extends Command
{
    protected $signature = 'configurator:translate-descriptions {locale : Target locale: it or en} {--category= : Optional product category} {--limit=0 : Maximum descriptions; 0 means all} {--force : Replace existing translations} {--catalog-only : Import matching catalog entries without using an external service}';

    protected $description = 'Translate imported product descriptions into the catalog and local database';

    public function handle(): int
    {
        $locale = mb_strtolower((string) $this->argument('locale'));
        if (! in_array($locale, ['it', 'en'], true)) {
            $this->error('Locale non supportata. Usa it oppure en.');
            return self::INVALID;
        }

        if (! $this->option('catalog-only') && ! filled(config('services.openai.api_key'))) {
            $this->error('OPENAI_API_KEY non configurata.');
            return self::FAILURE;
        }

        $target = 'body_html_'.$locale;
        $query = ConfiguratorProduct::query()->whereNotNull('body_html')->where('body_html', '!=', '')
            ->when($this->option('category'), fn ($q, $category) => $q->where('category', $category))
            ->when(! $this->option('force'), fn ($q) => $q->where(fn ($q) => $q->whereNull($target)->orWhere($target, '')))
            ->orderBy('id');
        $limit = max(0, (int) $this->option('limit'));
        if ($limit) $query->limit($limit);
        $products = $query->get(['id', 'handle', 'category', 'body_html', $target]);
        if ($products->isEmpty()) {
            $this->info('Nessuna descrizione da tradurre.');
            return self::SUCCESS;
        }

        $translated = 0;

        foreach ($products->groupBy('category') as $category => $categoryProducts) {
            $catalogPath = resource_path("data/{$category}-descriptions-{$locale}.json");
            $catalog = is_file($catalogPath) ? json_decode((string) file_get_contents($catalogPath), true) : [];
            $catalog = is_array($catalog) ? $catalog : [];
            foreach ($categoryProducts->chunk(5) as $batch) {
                $pending = $batch->filter(function ($product) use (&$catalog, $target, &$translated) {
                    $entry = $catalog[$product->handle] ?? null;
                    if (is_array($entry) && ($entry['source'] ?? null) === $product->body_html && filled($entry['translation'] ?? null)) {
                        $product->update([$target => $entry['translation']]);
                        $translated++;
                        return false;
                    }
                    return true;
                });
                if ($pending->isEmpty()) continue;
                if ($this->option('catalog-only')) continue;

                $translations = $this->translateBatch($pending->map(fn ($p) => [
                    'handle' => $p->handle,
                    'description_html' => $p->body_html,
                ])->values()->all(), $locale);
                foreach ($pending as $product) {
                    $translation = trim((string) ($translations[$product->handle] ?? ''));
                    if ($translation === '') continue;
                    $catalog[$product->handle] = ['source' => $product->body_html, 'translation' => $translation];
                    $product->update([$target => $translation]);
                    $translated++;
                }
                file_put_contents($catalogPath, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
            }
        }

        $this->info("Tradotte {$translated} descrizioni in {$locale} nei cataloghi di categoria.");
        return self::SUCCESS;
    }

    private function translateBatch(array $products, string $locale): array
    {
        $language = $locale === 'it' ? 'Italian' : 'English';
        $response = Http::withToken((string) config('services.openai.api_key'))->acceptJson()->timeout(180)->retry(
            3, 1500, fn (\Throwable $e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()),
        )->post('https://api.openai.com/v1/chat/completions', [
            'model' => config('services.openai.text_model', 'gpt-5-mini'),
            'messages' => [
                ['role' => 'system', 'content' => "Translate these Spanish car-radio product descriptions into {$language}. Preserve all product facts, vehicle makes/models/years, technical specifications, numbers, URLs and HTML structure/tags. Translate only visible natural-language text. Do not invent or omit claims. Return JSON only: {\"translations\":{\"product-handle\":\"translated HTML\"}}."],
                ['role' => 'user', 'content' => json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
            ],
            'response_format' => ['type' => 'json_object'],
        ])->throw();
        $data = json_decode((string) $response->json('choices.0.message.content'), true);
        if (! is_array($data['translations'] ?? null)) throw new RuntimeException('Risposta di traduzione non valida.');
        return $data['translations'];
    }
}
