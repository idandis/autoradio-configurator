<?php

namespace App\Console\Commands;

use App\Models\ConfiguratorProduct;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TranslateConfiguratorProductTitles extends Command
{
    protected $signature = 'configurator:translate-titles
        {locale=it : Target locale: it or en}
        {--category=screen : Product category to translate}
        {--limit=0 : Maximum titles to translate; 0 means all}
        {--force : Replace translations already stored}';

    protected $description = 'Translate imported configurator product titles without changing the Shopify source title';

    public function handle(): int
    {
        $locale = mb_strtolower((string) $this->argument('locale'));
        if (! in_array($locale, ['it', 'en'], true)) {
            $this->error('Locale non supportata. Usa it oppure en.');

            return self::INVALID;
        }

        if (! filled(config('services.openai.api_key'))) {
            $this->error('OPENAI_API_KEY non configurata.');

            return self::FAILURE;
        }

        $column = 'title_'.$locale;
        $query = ConfiguratorProduct::query()
            ->where('category', (string) $this->option('category'))
            ->when(! $this->option('force'), fn ($query) => $query->whereNull($column))
            ->orderBy('id');

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get(['id', 'title']);
        if ($products->isEmpty()) {
            $this->info('Nessun titolo da tradurre.');

            return self::SUCCESS;
        }

        $translated = 0;
        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($products->chunk(20) as $batch) {
            $translations = $this->translateBatch($batch->map(fn ($product) => [
                'id' => $product->id,
                'title' => $product->title,
            ])->values()->all(), $locale);

            foreach ($batch as $product) {
                $title = trim((string) ($translations[(string) $product->id] ?? ''));

                if ($title !== '' && mb_strlen($title) <= 1000) {
                    $product->update([$column => $title]);
                    $translated++;
                }

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Tradotti {$translated} titoli in {$locale}.");

        return $translated === $products->count() ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<int, array{id: int, title: string}> $products */
    private function translateBatch(array $products, string $locale): array
    {
        $language = $locale === 'it' ? 'Italian' : 'English';
        $response = Http::withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->timeout(120)
            ->retry(
                3,
                1500,
                fn (\Throwable $exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
            )
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.text_model', 'gpt-5-mini'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Translate e-commerce car-radio product titles into {$language}. Preserve vehicle makes, models, years, screen sizes, RAM/storage values, product names, technical acronyms and capitalization such as CarPlay, Android Auto, GPS, WiFi, CIC and CCC. Translate only natural-language words. Do not add claims or remove specifications. Return JSON only as {\"translations\":{\"product_id\":\"translated title\"}}.",
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
            ])
            ->throw();

        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;
        $translations = is_array($decoded) ? ($decoded['translations'] ?? null) : null;

        if (! is_array($translations)) {
            throw new RuntimeException('Il servizio di traduzione ha restituito una risposta non valida.');
        }

        return $translations;
    }
}
