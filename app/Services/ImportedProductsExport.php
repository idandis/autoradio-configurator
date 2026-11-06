<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class ImportedProductsExport
{
    public function date(ConfiguratorProduct $product): string
    {
        $value = $product->meta['imported_at'] ?? $product->meta['autogenesi']['imported_at'] ?? $product->created_at;
        return CarbonImmutable::parse($value)->setTimezone('Europe/Rome')->format('Y-m-d');
    }

    public function dates(): array
    {
        return ConfiguratorProduct::whereIn('category', ['screen', 'camera', 'speaker', 'accessory'])->get(['id', 'meta', 'created_at'])
            ->map(fn ($p) => $this->date($p))->unique()->sortDesc()->values()->all();
    }

    public function idsOnDate(string $date): array
    {
        return ConfiguratorProduct::whereIn('category', ['screen', 'camera', 'speaker', 'accessory'])->get(['id', 'meta', 'created_at'])
            ->filter(fn ($p) => $this->date($p) === $date)->pluck('id')->all();
    }

    public function write(string $date): string
    {
        $ids = $this->idsOnDate($date);
        $products = ConfiguratorProduct::with('variants')->whereIn('id', $ids)->orderBy('id')->get();
        if ($products->isEmpty()) throw new \RuntimeException('Nessun prodotto importato nella data selezionata.');
        $template = app(AutogenesiTemplateExport::class);
        $rows = [];
        $analysis = [];
        foreach ($products as $p) {
            if (mb_strlen($p->body_html ?? '') > 32767) throw new \RuntimeException('Descrizione troppo lunga per Excel: '.$p->title);
            $images = collect([$p->image_url, ...($p->meta['gallery_images'] ?? []), ...$p->variants->pluck('image_url')->all(), ...array_column($p->meta['original_dashboard_images'] ?? [], 'url')])->filter()->unique()->values()->all();
            $dashboard = $p->meta['original_dashboard_images'][0] ?? null;
            $reference = $dashboard['shopify_file_reference'] ?? '';
            if (! $reference && isset($dashboard['url'])) {
                $dashboardUrl = $this->imageUrl($dashboard['url']);
                if ($dashboardUrl) $reference = rawurldecode(basename(parse_url($dashboardUrl, PHP_URL_PATH)));
            }
            $base = array_replace(array_fill_keys($template->columns(), ''), [
                'Title' => $p->title, 'Handle' => $p->handle, 'ID' => $p->meta['shopify_product_id'] ?? '',
                'Type' => ($p->meta['type'] ?? null) ?: match ($p->category) { 'screen' => 'Radio AM/FM', 'camera' => 'Cámara', 'speaker' => 'Altavoces', default => 'Accesorios' },
                'Body HTML' => $p->body_html ?? '', 'Status' => 'Active', AutogenesiTemplateExport::BRAND => $p->brand ?? '',
                AutogenesiTemplateExport::MODEL => empty($p->model) ? '' : (preg_match('/^\s*\d+\s*[:：]/u', $p->model) ? $p->model : '1:'.$p->model),
                AutogenesiTemplateExport::YEAR => $p->year_from && $p->year_to ? $p->year_from.'-'.$p->year_to : '',
                AutogenesiTemplateExport::DIN => $p->meta['din'] ?? '', AutogenesiTemplateExport::DASHBOARD => $reference,
                'Metafield: custom.altavoces [single_line_text_field]' => implode(' | ', (array) ($p->meta['speaker_sizes'] ?? [])),
                'Metafield: shopify.vehicle-coaxial-speaker-nominal-size [list.metaobject_reference]' => $p->meta['speaker_nominal_size'] ?? '',
            ]);
            $variants = $p->variants->values();
            $offset = count($rows);
            for ($i = 0; $i < max($variants->count(), count($images), 1); $i++) {
                $row = $base;
                if ($i > 0) {
                    $row['Body HTML'] = '';
                    foreach ($row as $key => $value) if (str_starts_with($key, 'Metafield:')) $row[$key] = '';
                }
                if ($v = $variants[$i] ?? null) {
                    $row['Option1 Value'] = $v->option_value ?: $v->title;
                    $row['Variant ID'] = $v->shopify_variant_id ?? '';
                    $row['Variant SKU'] = $v->sku ?? '';
                    $row['Variant Price'] = $v->price ?? '';
                    foreach (['Italia' => 'italia', 'Resto del Mondo' => 'resto_del_mondo', 'USA-CANADA' => 'usa_canada', 'spagna' => 'spagna'] as $label => $key) $row['Price / '.$label] = $v->meta['regional_prices'][$key] ?? '';
                } elseif ($i === 0 && $variants->isEmpty()) {
                    $row['Option1 Value'] = 'Default Title';
                    $row['Variant Price'] = $p->price_min ?? '';
                }
                $row['Image Src'] = isset($images[$i]) ? $this->imageUrl($images[$i]) : '';
                if (isset($images[$i]) && ! $row['Image Src']) $analysis[] = $this->note($p, 'Immagine', 'Da completare', 'URL pubblico non disponibile: '.$images[$i]);
                $rows[] = $row;
            }
            $analysis[] = $this->note($p, 'Data importazione', 'Esportato', $date.' · Europe/Rome · Products!A'.($offset + 2));
            if ($dashboard && ! $reference) $analysis[] = $this->note($p, 'Foto cruscotto', 'Da completare', 'La foto è selezionata nel configuratore. Per il metafield Shopify serve il nome esatto o GID del file già caricato in Shopify Files.');
            foreach ($p->meta['vehicle_compatibilities'] ?? [] as $entry) $analysis[] = $this->note($p, 'Compatibilità', 'Intervallo specifico', $entry['brand'].' '.$entry['model'].' '.$entry['year_from'].'-'.$entry['year_to']);
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory('imported-products/exports');
        $path = $disk->path('imported-products/exports/prodotti-importati-'.$date.'-shopify.xlsx');
        $template->writeRows($rows, $analysis, $path);
        return $path;
    }

    private function note(ConfiguratorProduct $p, string $field, string $state, string $rule): array
    {
        return ['Prodotto' => $p->title, 'Campo' => $field, 'Stato' => $state, 'Valore' => '', 'Origine / regola' => $rule];
    }

    private function imageUrl(string $value): string
    {
        $parts = parse_url($value);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        if (str_starts_with($path, '/storage/') && (! $host || in_array($host, ['localhost', '127.0.0.1'], true))) {
            return rtrim(config('autogenesi.media_base_url'), '/').substr($path, strlen('/storage'));
        }
        if (! filter_var($value, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https' || ! $host || filter_var($host, FILTER_VALIDATE_IP)
            || preg_match('/(?:^|\.)(?:localhost|local|internal)$/', $host) || isset($parts['user']) || isset($parts['pass'])) return '';
        return $value;
    }
}
