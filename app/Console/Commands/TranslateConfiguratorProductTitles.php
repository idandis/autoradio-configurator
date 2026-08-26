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

        $column = 'title_'.$locale;
        $query = ConfiguratorProduct::query()
            ->where('category', (string) $this->option('category'))
            ->when(! $this->option('force'), fn ($query) => $query->whereNull($column))
            ->orderBy('id');

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get(['id', 'handle', 'title']);
        if ($products->isEmpty()) {
            $this->info('Nessun titolo da tradurre.');

            return self::SUCCESS;
        }

        $translated = 0;
        $bar = $this->output->createProgressBar($products->count());
        $bar->start();
        $catalog = $this->translationCatalog($locale);
        $remaining = $products->filter(function ($product) use ($catalog, $column, $locale, &$translated, $bar) {
            $entry = $catalog[$product->handle] ?? null;
            $title = is_array($entry) && ($entry['source'] ?? null) === $product->title
                ? trim((string) ($entry['translation'] ?? ''))
                : '';

            if ($title === '') {
                $title = $this->translateUpdatedTitle((string) $product->title, $locale);
            }

            if ($title === '' || mb_strlen($title) > 1000) {
                return true;
            }

            $product->update([$column => $title]);
            $translated++;
            $bar->advance();

            return false;
        })->values();

        if ($remaining->isNotEmpty() && ! filled(config('services.openai.api_key'))) {
            $bar->advance($remaining->count());
            $bar->finish();
            $this->newLine(2);
            $this->info("Importate {$translated} traduzioni dal catalogo interno.");
            $this->warn("Senza OPENAI_API_KEY restano {$remaining->count()} titoli non presenti nel catalogo interno.");

            return self::SUCCESS;
        }

        foreach ($remaining->chunk(20) as $batch) {
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

    /** @return array<string, array{source: string, translation: string}> */
    private function translationCatalog(string $locale): array
    {
        $path = resource_path("data/screen-titles-{$locale}.json");

        if (! is_file($path)) {
            return [];
        }

        $catalog = json_decode((string) file_get_contents($path), true);

        return is_array($catalog) ? $catalog : [];
    }

    private function translateUpdatedTitle(string $title, string $locale): string
    {
        $phrases = $locale === 'it' ? [
            'Android Auto Inalámbrico' => 'Android Auto Wireless',
            'Apple CarPlay Inalámbrico' => 'Apple CarPlay Wireless',
            'con Visión Nocturna' => 'con Visione Notturna',
            'con Líneas de Guiado' => 'con Linee Guida',
            'Doble Din' => 'Doppio DIN',
            'Pantalla Táctil' => 'Schermo Touchscreen',
            'Pantalla' => 'Schermo',
            'Cámara Trasera' => 'Telecamera Posteriore',
            'Cámara Frontal' => 'Telecamera Anteriore',
            'para Coche' => 'per Auto',
        ] : [
            'Android Auto Inalámbrico' => 'Wireless Android Auto',
            'Apple CarPlay Inalámbrico' => 'Wireless Apple CarPlay',
            'con Visión Nocturna' => 'with Night Vision',
            'con Líneas de Guiado' => 'with Guide Lines',
            'Doble Din' => 'Double DIN',
            'Pantalla Táctil' => 'Touchscreen',
            'Pantalla' => 'Screen',
            'Cámara Trasera' => 'Rear Camera',
            'Cámara Frontal' => 'Front Camera',
            'para Coche' => 'for Car',
        ];

        $translated = str_ireplace(array_keys($phrases), array_values($phrases), $title);
        $words = $locale === 'it'
            ? ['para' => 'per', 'y' => 'e', 'conector' => 'connettore']
            : ['para' => 'for', 'y' => 'and', 'con' => 'with', 'conector' => 'connector'];

        foreach ($words as $source => $translation) {
            $translated = preg_replace('/\b'.preg_quote($source, '/').'\b/iu', $translation, $translated) ?? $translated;
        }

        return trim($translated);
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
