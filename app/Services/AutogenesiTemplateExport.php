<?php

namespace App\Services;

use App\Models\AutogenesiProduct;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AutogenesiTemplateExport
{
    public const SOURCE = 'Metafield: custom.aliexpress_url [url]';

    public const BRAND = 'Metafield: custom.radio_type [single_line_text_field]';

    public const DIN = 'Metafield: custom.dimensioni_schermo [single_line_text_field]';

    public const MODEL = 'Metafield: custom.modello_auto [single_line_text_field]';

    public const YEAR = 'Metafield: custom.anno [single_line_text_field]';

    public const DASHBOARD = 'Metafield: custom.foto_cruscotto_originale [list.file_reference]';

    public function columns(): array
    {
        return [...json_decode(file_get_contents(resource_path('autogenesi/export-columns.json')), true, flags: JSON_THROW_ON_ERROR), 'Status'];
    }

    public function rows(AutogenesiProduct $p): array
    {
        $images = $this->images($p);
        $base = array_replace(array_fill_keys($this->columns(), ''), [
            'Title' => $p->title, 'Handle' => $p->handle, 'ID' => $p->metadata['shopify_product_id'] ?? '',
            'Type' => 'Radio AM/FM', 'Body HTML' => $p->body_html, self::BRAND => mb_strtoupper($p->brand),
            self::DIN => $this->din($p), self::MODEL => preg_match('/^\s*\d+\s*[:：]/u', $p->model) ? $p->model : '1:'.$p->model, self::YEAR => $p->year_from.'-'.$p->year_to,
            self::DASHBOARD => $this->dashboard($p), 'Status' => 'Active',
        ]);
        $rows = [];
        for ($i = 0; $i < max(count($p->variants), count($images), 1); $i++) {
            $row = $base;
            if ($i > 0) {
                $row['Body HTML'] = '';
                foreach (array_keys($row) as $column) {
                    if (str_starts_with($column, 'Metafield:')) {
                        $row[$column] = '';
                    }
                }
            }
            if ($variant = $p->variants[$i] ?? null) {
                $row['Option1 Value'] = $variant['label'];
                $row['Variant ID'] = $variant['shopify_variant_id'] ?? '';
                $row['Variant SKU'] = $variant['sku'];
                $row['Variant Price'] = $variant['selling_price'] ?? '';
                foreach (['Italia' => 'italia', 'Resto del Mondo' => 'resto_del_mondo', 'USA-CANADA' => 'usa_canada', 'spagna' => 'spagna'] as $label => $key) {
                    $row['Price / '.$label] = $variant['regional_prices'][$key] ?? '';
                }
            }
            if ($image = $images[$i] ?? null) {
                $row['Image Src'] = $this->publicUrl($image);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function analysis(AutogenesiProduct $p): array
    {
        $rows = $this->rows($p);
        $images = $this->images($p);
        $map = [
            'Title' => ['Bozza', 'Titolo corrente, comprese le modifiche manuali.'],
            'Image Src' => ['Foto', 'URL pubblico della foto corrente; mai URL fornitore di una foto modificata o localhost.'],
            'Option1 Value' => ['Varianti', 'Nome attuale di ciascuna variante; le righe aggiuntive di sole immagini restano senza variante.'],
            'Handle' => ['Bozza', 'Handle univoco già memorizzato.'],
            'ID' => ['Assegnato da Shopify', 'Vuoto per un nuovo prodotto: non usare ID locale o ID AliExpress.'],
            'Variant ID' => ['Assegnato da Shopify', 'Vuoto per nuove varianti; non copiare gli ID del prodotto di esempio.'],
            'Type' => ['Classificazione', 'Radio AM/FM, come il modello allegato.'],
            'Variant Price' => ['Varianti', 'Prezzo di vendita attuale salvato; non il costo fornitore né una vecchia stima.'],
            'Variant SKU' => ['Varianti', 'SKU interno stabile; non rinumerare dopo eliminazioni.'],
            'Body HTML' => ['Bozza', 'Descrizione HTML corrente, comprese le modifiche manuali.'],
            self::BRAND => ['Vettura', 'Marca in maiuscolo dai campi compatibilità.'],
            self::DIN => ['Titolo / specifiche', 'Questo metafield contiene il DIN (1DIN/2DIN), non i pollici, come nel modello e nell’importatore.'],
            self::MODEL => ['Vettura', '1: associa il modello alla prima marca, come nel formato allegato.'],
            self::YEAR => ['Vettura', 'Intervallo anni salvato nella bozza.'],
            self::DASHBOARD => ['Shopify Files', 'Foto scelta con il flag Cruscotto originale. Nome file o GID Shopify; caricare prima la foto in Shopify Files, non usare un URL Aruba.'],
            'Status' => ['Aggiunta necessaria', 'Active: il prodotto viene importato come attivo, come richiesto.'],
        ];
        $audit = [];
        foreach ($this->columns() as $column) {
            $values = array_values(array_unique(array_filter(array_column($rows, $column), fn ($v) => $v !== '' && $v !== null)));
            [$state, $rule] = $map[$column] ?? ['Non applicabile', 'Campo altoparlanti: non applicabile a questa autoradio; vuoto, come nel modello.'];
            if (str_starts_with($column, 'Price / ')) {
                [$state, $rule] = ['Vuoto come da esempio', 'Nessun prezzo di mercato aggiuntivo: conservata la scelta dell’utente.'];
            }
            if ($values && ! in_array($column, ['ID', 'Variant ID'], true)) {
                $state = 'Compilato';
            }
            if (! $values && in_array($column, ['Title', 'Handle', 'Option1 Value', 'Variant Price', 'Variant SKU', 'Body HTML', self::BRAND, self::MODEL, self::YEAR, self::DIN, self::DASHBOARD], true)) {
                $state = 'Da completare';
            }
            if ($column === 'Image Src') {
                $state = count(array_filter(array_map(fn ($i) => $this->publicUrl($i), $images))) === count($images) && $images ? 'Compilato' : 'Da completare';
            }
            if ($column === 'Image Src' && collect($images)->contains(fn ($i) => ! empty($i['aruba_url']) && $this->publicUrl($i) === $i['aruba_url'])) {
                $state = 'Predisposto Aruba';
                $rule = 'URL di produzione predisposto: caricare prima storage/app/public/autogenesi/catalog/ su Aruba con FileZilla e verificare accesso pubblico.';
            }
            if ($column === 'Body HTML') {
                $values = [mb_strlen($p->body_html).' caratteri HTML, testo completo in Products!J.'];
            }
            $audit[] = ['Campo' => $column, 'Stato' => $state, 'Valore' => implode(' | ', $values), 'Origine / regola' => $rule];
        }
        foreach ($images as $index => $image) {
            $url = $this->publicUrl($image);
            $local = ! empty($image['configurator_path']) && ($image['configurator_sha256'] ?? null) === $image['sha256']
                ? 'Configuratore: foto già copiata in /storage/'.$image['configurator_path'].'. ' : '';
            $staged = $url && $url === ($image['aruba_url'] ?? null);
            $audit[] = ['Campo' => 'Foto '.($index + 1).' · '.$image['role'], 'Stato' => $staged ? 'Da caricare su Aruba' : ($url ? 'Compilato' : 'Da completare'),
                'Valore' => $url ?: basename($image['path']), 'Origine / regola' => $local.'Products!B'.($index + 2).': '.($url ? ($staged ? 'URL predisposto. Caricare storage/app/public/autogenesi/catalog/ su Aruba con FileZilla prima di usare il file in Shopify.' : 'URL della foto corrente.') : 'Per Shopify o import remoto: caricare la foto su hosting pubblico e inserire l’URL. File locale conservato nella bozza.')];
        }
        foreach ($p->variants as $index => $variant) {
            if (empty($variant['selling_price'])) {
                $audit[] = ['Campo' => 'Prezzo · '.$variant['sku'], 'Stato' => 'Da completare', 'Valore' => '', 'Origine / regola' => 'Manca il prezzo di vendita della variante, riga Products '.($index + 2).'.'];
            }
        }
        foreach ($p->metadata['warnings'] ?? [] as $warning) {
            if (preg_match('/\bDIN\b|core count|corrispondenza univoca|RAM\/ROM/iu', $warning)) {
                $audit[] = ['Campo' => 'Verifica tecnica', 'Stato' => 'Da verificare', 'Valore' => '', 'Origine / regola' => $warning];
            }
        }

        return $audit;
    }

    public function write(AutogenesiProduct $p, string $path): void
    {
        $this->writeMany([$p], $path);
    }

    public function writeMany(iterable $drafts, string $path): void
    {
        $rows = [];
        $analysis = [];
        $notes = [];
        foreach ($drafts as $p) {
            if (mb_strlen($p->body_html) > 32767) {
                throw new \RuntimeException('Descrizione troppo lunga per Excel: '.$p->title);
            }
            $offset = count($rows);
            array_push($rows, ...$this->rows($p));
            foreach ($this->analysis($p) as $item) {
                $item['Origine / regola'] = preg_replace_callback('/Products!([A-Z]+)(\d+)/', fn ($m) => 'Products!'.$m[1].((int) $m[2] + $offset), $item['Origine / regola']);
                $analysis[] = ['Prodotto' => $p->title] + $item;
            }
            foreach ($this->images($p) as $index => $image) {
                $url = $this->publicUrl($image);
                if (! $url) {
                    $notes['B'.($offset + $index + 2)] = 'URL pubblico mancante per '.basename($image['path']).'. Caricare la foto su un indirizzo pubblico prima dell’importazione.';
                } elseif ($url === ($image['aruba_url'] ?? null)) {
                    $notes['B'.($offset + $index + 2)] = 'URL predisposto per Aruba: caricare prima storage/app/public/autogenesi/catalog/ con FileZilla e verificare l’accesso pubblico.';
                }
            }
            if (! $this->dashboard($p)) {
                $notes['U'.($offset + 2)] = 'Manca il riferimento Shopify della foto cruscotto.';
            }
        }
        if (! $rows) {
            throw new \RuntimeException('Nessuna bozza da esportare.');
        }
        $this->writeRows($rows, $analysis, $path, $notes);
    }

    public function writeRows(array $rows, array $analysis, string $path, array $notes = []): void
    {
        if (! $rows) throw new \RuntimeException('Nessun prodotto da esportare.');
        $book = new Spreadsheet;
        $products = $book->getActiveSheet()->setTitle('Products');
        $this->fill($products, $rows);
        $products->freezePane('A2')->setAutoFilter($products->calculateWorksheetDimension());
        foreach (['A' => 55, 'B' => 65, 'C' => 25, 'D' => 50, 'J' => 75] as $column => $width) {
            $products->getColumnDimension($column)->setWidth($width);
        }
        $products->getStyle('J2:J'.$products->getHighestRow())->getAlignment()->setWrapText(true);
        foreach ($notes as $cell => $note) {
            $products->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFFE7A0');
            $products->getComment($cell)->setAuthor('AutoGenesi')->getText()->createText($note);
        }
        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    private function fill(Worksheet $sheet, array $rows): void
    {
        foreach ([array_keys($rows[0]), ...array_map('array_values', $rows)] as $i => $row) {
            foreach ($row as $j => $value) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($j + 1).($i + 1), (string) $value, DataType::TYPE_STRING);
            }
        }
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF20354A');
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->getColor()->setARGB('FFFFFFFF');
    }

    private function images(AutogenesiProduct $p): array
    {
        return collect($p->images)->values()->map(function ($image) use ($p) {
            $file = app(AutogenesiArubaImages::class)->descriptor($p, $image);
            if ($file) {
                $image['aruba_url'] = $file['url'];
            }

            return $image;
        })->all();
    }

    private function publicUrl(array $image): string
    {
        $candidates = [$image['shopify_url'] ?? '', $image['public_url'] ?? '', $image['aruba_url'] ?? ''];
        if (! empty($image['configurator_path']) && ($image['configurator_sha256'] ?? null) === $image['sha256']) {
            $candidates[] = Storage::disk('public')->url($image['configurator_path']);
        }
        if (empty($image['uploaded']) && empty($image['ai_edited']) && empty($image['square_cropped']) && empty($image['manually_cropped'])) {
            $candidates[] = $image['source_url'] ?? '';
        }
        foreach ($candidates as $url) {
            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! $host || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
                continue;
            }
            $ip = trim($host, '[]');
            if (filter_var($ip, FILTER_VALIDATE_IP) && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                continue;
            }

            return $url;
        }

        return '';
    }

    private function din(AutogenesiProduct $p): string
    {
        if (preg_match('/\b([12])\s*DIN\b/i', $p->title, $match)) {
            return $match[1].'DIN';
        }
        foreach ($p->metadata['description_source']['specifications'] ?? [] as $spec) {
            if (strtoupper(trim($spec['name'])) !== 'DIN') {
                continue;
            }
            $value = mb_strtolower(trim($spec['value']));
            if (preg_match('/\b([12])\s*din\b/', $value, $match)) {
                return $match[1].'DIN';
            }
            if (in_array($value, ['un din', 'one din', 'single din'], true)) {
                return '1DIN';
            }
            if (in_array($value, ['dos din', 'two din', 'double din'], true)) {
                return '2DIN';
            }
        }

        return '';
    }

    private function dashboard(AutogenesiProduct $p): string
    {
        $image = AutogenesiImageLayout::dashboard($p);
        if (! empty($image['shopify_file_reference'])) return $image['shopify_file_reference'];

        return '';
    }
}
