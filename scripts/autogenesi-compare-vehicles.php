<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\ConfiguratorProduct;
use App\Services\VehicleImageResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

$dir = base_path('docs/autogenesi');
$input = $argv[1] ?? $dir.'/top100-europa-2020-01-09.csv';
$cutoff = 2020;
$normalize = fn ($s) => preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($s)));
$family = function ($brand, $model) use ($normalize) {
    $model = Str::lower(Str::ascii(trim($model)));
    $model = preg_replace('/\b(?:clase|classe|class)\b/', 'classe', $model);
    $model = preg_replace('/\s+(?:[a-z]\d[a-z0-9]*|\d[a-z])$/', '', $model);
    if ($brand === 'renault') {
        $model = preg_replace('/\b(clio|captur)\s+\d+$/', '$1', $model);
    }
    if ($brand === 'opel' && $normalize($model) === 'grandlandx') {
        $model = 'grandland';
    }

    return $normalize($model);
};
$resolver = app(VehicleImageResolver::class);
$entries = [];
$products = ConfiguratorProduct::where('category', 'screen')->get();
foreach ($products as $p) {
    if (($p->meta['type'] ?? '') === 'DASHCAM') {
        continue;
    }
    foreach ($resolver->vehicleEntries($p->brand, $p->model) as $e) {
        $brand = $normalize($e['brand']);
        $brand = $brand === 'mercedesbenz' ? 'mercedes' : $brand;
        $entries[] = ['brand' => $brand, 'model' => $family($brand, $e['model']),
            'label' => $e['model'], 'from' => $p->year_from, 'to' => $p->year_to, 'handle' => $p->handle];
    }
}
$f = fopen($input, 'r');
if (! $f) {
    throw new RuntimeException('Lista sorgente non disponibile.');
}
$headers = fgetcsv($f, escape: '');
$rows = [];
while (($row = fgetcsv($f, escape: '')) !== false) {
    if (count($row) !== count($headers)) {
        throw new RuntimeException('Riga sorgente non valida.');
    }
    $r = array_combine($headers, $row);
    $brand = $normalize($r['marca']);
    $model = $family($brand, $r['modello']);
    $matches = array_values(array_filter($entries, fn ($e) => $e['brand'] === $brand && $e['model'] === $model));
    $older = array_values(array_filter($matches, fn ($e) => $e['from'] !== null && $e['from'] <= $cutoff));
    $unknown = array_filter($matches, fn ($e) => $e['from'] === null);
    $status = $older ? 'presente' : ($unknown ? 'presente_anni_da_verificare' : ($matches ? 'solo_post_2020' : 'assente'));
    $note = $older ? 'Presente almeno una generazione entro il 2020; copertura completa per anno/allestimento non verificata.' : '';
    if (! $matches && (($brand === 'mini' && $model === 'hatch') || ($brand === 'mitsubishi' && $model === 'spacestar'))) {
        $possible = $brand === 'mini' ? 'mini' : 'mirage';
        $matches = array_values(array_filter($entries, fn ($e) => $e['brand'] === $brand && $e['model'] === $possible));
        if ($matches) {
            $status = 'denominazione_da_verificare';
            $note = 'Possibile corrispondenza con '.implode(' / ', array_unique(array_column($matches, 'label'))).'; non conteggiato come assente.';
        }
    }
    $r['stato_db'] = $status;
    $r['modelli_db'] = implode(' | ', array_unique(array_column($matches, 'label')));
    $r['anni_db'] = implode(' | ', array_unique(array_map(fn ($e) => ($e['from'] ?? '?').'-'.($e['to'] ?? '?'), $matches)));
    $r['prodotti_db'] = implode(' | ', array_unique(array_column($matches, 'handle')));
    $r['note'] = $note;
    $rows[] = $r;
}
fclose($f);
if (count($rows) !== 100 || count(array_unique(array_map(fn ($r) => $normalize($r['marca'].' '.$r['modello']), $rows))) !== 100) {
    throw new RuntimeException('La lista deve contenere esattamente 100 modelli distinti.');
}
$write = function ($path, $records) use ($rows) {
    $f = fopen($path, 'w');
    fputcsv($f, array_keys($rows[0]), escape: '');
    foreach ($records as $r) {
        fputcsv($f, $r, escape: '');
    }
    fclose($f);
};
$missing = array_values(array_filter($rows, fn ($r) => $r['stato_db'] === 'assente'));
$review = array_values(array_filter($rows, fn ($r) => ! in_array($r['stato_db'], ['presente', 'assente'], true)));
$write($dir.'/confronto-top100-2020.csv', $rows);
$write($dir.'/modelli-assenti-pre2021.csv', $missing);
$write($dir.'/modelli-da-verificare-pre2021.csv', $review);
$counts = array_count_values(array_column($rows, 'stato_db'));
$date = now('Europe/Rome')->format('Y-m-d H:i');
$text = "# AutoGenesi — modelli precedenti al 2021\n\nConfronto locale: {$date} (Europe/Rome).\n\n";
$text .= "Base: 100 modelli della classifica [Autonoción, gennaio–settembre 2020](https://www.autonocion.com/top100-modelos-europa-9meses/), pubblicata il 15 novembre 2020. Non è la classifica annuale 2020, né una graduatoria cumulativa del parco circolante. Il perimetro dei paesi non è precisato nell'articolo.\n\n";
$text .= "Controlli di contesto: [L’Argus, 2020 completo, 25 paesi](https://www.largus.fr/actualite-automobile/les-50-voitures-les-plus-vendues-en-europe-en-2020-10557410.html) e [Fiches Auto, classifiche storiche](https://www.fiches-auto.fr/articles-auto/chiffres-de-l-auto/s-1773-voitures-les-plus-vendues-en-europe.php). Periodi e perimetri diversi: nessun dato è stato mescolato nella lista sorgente.\n\n";
$text .= "La posizione è quella pubblicata dalla fonte. La fonte presenta volumi non ordinati per BMW Serie 1 e Hyundai i10; posizione e volumi sono conservati senza correggerli arbitrariamente. Nomi normalizzati: VW → Volkswagen; Opel/Vauxhall → Opel; Clase → Classe; Serie3/Serie1/Serie5 separati; C3 Aicross → C3 Aircross; Up! → Up; Model3 → Model 3; SpaceStar → Space Star.\n\n";
$text .= "Confronto esclusivamente sui campi marca, modello e anni di configurator_products (categoria screen, escluse DASHCAM), separando le associazioni multiple. Titoli e descrizioni non sono usati. Normalizzazione di accenti, spazi, trattini, codici di generazione; Grandland X raggruppata con Grandland.\n\n";
$text .= "Presente significa almeno un prodotto per quella famiglia modello con year_from entro il 2020; non certifica tutti gli anni o la generazione venduta nel 2020. I modelli distinti (C3/C3 Aircross, 500/500X, Corolla/Corolla Verso) restano separati. I soli modelli successivi al 2020 e i dati senza anno vengono tenuti da verificare. Nessuna disponibilità di autoradio AliExpress è stata verificata.\n\n";
$text .= 'Esito: '.json_encode($counts, JSON_UNESCAPED_UNICODE).".\n\n## Modelli assenti\n\n| Posizione fonte | Marca | Modello |\n|---|---|---|\n";
foreach ($missing as $r) {
    $text .= "| {$r['posizione_fonte']} | {$r['marca']} | {$r['modello']} |\n";
}
$text .= "\n## Da verificare\n\n";
foreach ($review as $r) {
    $text .= "- {$r['marca']} {$r['modello']}: {$r['stato_db']}. {$r['note']}\n";
}
$text .= "\n## Ripetere il confronto\n\nEseguire dalla radice del progetto: `php scripts/autogenesi-compare-vehicles.php`. La classifica è una fotografia documentata, non una ricerca web automatica. Il confronto rilegge il database corrente e aggiorna i tre CSV e questo rapporto; non modifica il database.\n";
file_put_contents($dir.'/rapporto-modelli-pre2021.md', $text);
echo json_encode(['totale' => count($rows), 'esiti' => $counts, 'assenti' => array_map(fn ($r) => $r['marca'].' '.$r['modello'], $missing), 'da_verificare' => array_map(fn ($r) => $r['marca'].' '.$r['modello'], $review)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
