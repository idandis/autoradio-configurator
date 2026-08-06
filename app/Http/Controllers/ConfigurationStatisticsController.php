<?php

namespace App\Http\Controllers;

use App\Models\ConfigurationStatistic;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ConfigurationStatisticsController extends Controller
{
    private const EVENTS = ['quote_downloaded', 'checkout_clicked'];

    private const LANGUAGES = ['es', 'it', 'en'];

    private const ANALYSES = [
        'brands', 'models', 'years', 'products', 'variants', 'prices',
        'installations', 'cameras', 'zones', 'languages', 'conversions', 'timeline',
    ];

    public function __invoke(Request $request): Response
    {
        $filters = $this->filters($request);
        $query = $this->filteredQuery($filters);
        $quoteQuery = (clone $query)->where('event_type', 'quote_downloaded');
        $quoteCount = (clone $quoteQuery)->count();
        $checkoutCount = (clone $query)->where('event_type', 'checkout_clicked')->count();
        $configurationQuery = $this->uniqueConfigurations($query);
        $valueExpression = 'COALESCE(configuration_value, product_price, 0)';

        return Inertia::render('ConfigurationStatistics', [
            'events' => (clone $query)->latest()->paginate(50)->withQueryString(),
            'filters' => $filters,
            'filterOptions' => [
                'brands' => $this->distinctValues('brand'),
                'models' => $this->distinctValues('model'),
                'languages' => $this->distinctValues('language'),
                'productTypes' => $this->distinctValues('product_type'),
                'zones' => $this->distinctValues('service_zone'),
            ],
            'stats' => [
                'quote_downloaded' => $quoteCount,
                'checkout_clicked' => $checkoutCount,
                'total' => (clone $query)->count(),
                'quote_value' => (float) (clone $quoteQuery)->selectRaw("COALESCE(SUM({$valueExpression}), 0) AS aggregate")->value('aggregate'),
                'average_quote_value' => (float) (clone $quoteQuery)->selectRaw("COALESCE(AVG({$valueExpression}), 0) AS aggregate")->value('aggregate'),
                'top_brand' => $this->topValue($configurationQuery, 'brand'),
                'top_model' => $this->topValue($configurationQuery, 'model'),
                'top_product' => $this->topValue($configurationQuery, 'product_title'),
                'payment_progression_rate' => $quoteCount > 0 ? round(($checkoutCount / $quoteCount) * 100, 1) : 0,
            ],
            'analysis' => $this->analysis($query, $filters['analysis'], $filters['visualization'], $filters['counting_mode']),
            'insights' => $this->insights($query, $quoteQuery, $filters),
        ]);
    }

    public function export(Request $request, string $format)
    {
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 404);

        $filters = $this->filters($request);
        $events = $this->filteredQuery($filters)->latest()->get();

        if ($format === 'pdf') {
            return response()->view('configuration-statistics-pdf', [
                'events' => $events,
                'generatedAt' => now(),
            ]);
        }

        $filename = 'statistiche-configuratore-'.now()->format('Y-m-d-His').'.'.$format;

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($events): void {
                $stream = fopen('php://output', 'wb');
                fputcsv($stream, $this->exportHeaders());
                foreach ($events as $event) fputcsv($stream, $this->exportRow($event));
                fclose($stream);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->streamDownload(function () use ($events): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray($this->exportHeaders(), null, 'A1');
            $row = 2;
            foreach ($events as $event) $sheet->fromArray($this->exportRow($event), null, 'A'.$row++);
            $sheet->getStyle('A1:Q1')->getFont()->setBold(true);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function destroy(ConfigurationStatistic $configurationStatistic): RedirectResponse
    {
        $configurationStatistic->delete();

        return back()->with('success', 'Evento statistico eliminato.');
    }

    public function destroySelected(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:configuration_statistics,id'],
        ]);

        ConfigurationStatistic::query()->whereIn('id', $validated['ids'])->delete();

        return back()->with('success', count($validated['ids']).' eventi statistici eliminati.');
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'in:CANCELLA'],
        ]);

        ConfigurationStatistic::query()->delete();

        return back()->with('success', 'Tutte le statistiche sono state cancellate.');
    }

    private function filters(Request $request): array
    {
        return [
            'search' => trim($request->string('search')->toString()),
            'date_from' => $this->dateFilter($request->string('date_from')->toString()),
            'date_to' => $this->dateFilter($request->string('date_to')->toString()),
            'event_type' => $this->allowedFilter($request->string('event_type')->toString(), self::EVENTS),
            'brand' => trim($request->string('brand')->toString()),
            'model' => trim($request->string('model')->toString()),
            'product_type' => trim($request->string('product_type')->toString()),
            'installation' => $this->allowedFilter($request->string('installation')->toString(), ['yes', 'no']),
            'camera' => $this->allowedFilter($request->string('camera')->toString(), ['yes', 'no']),
            'price_range' => $this->allowedFilter($request->string('price_range')->toString(), ['0-100', '100-250', '250-500', '500-1000', '1000+']),
            'zone' => trim($request->string('zone')->toString()),
            'language' => $this->allowedFilter($request->string('language')->toString(), self::LANGUAGES),
            'analysis' => $this->allowedFilter($request->string('analysis')->toString(), self::ANALYSES) ?? 'brands',
            'visualization' => $this->allowedFilter($request->string('visualization')->toString(), ['auto', 'table', 'bar', 'pie', 'line']) ?? 'auto',
            'counting_mode' => $this->allowedFilter($request->string('counting_mode')->toString(), ['unique', 'events']) ?? 'unique',
        ];
    }

    private function filteredQuery(array $filters): Builder
    {
        $valueExpression = 'COALESCE(configuration_value, product_price, 0)';

        return ConfigurationStatistic::query()
            ->whereIn('event_type', self::EVENTS)
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = '%'.$filters['search'].'%';
                $query->where(function (Builder $nested) use ($search): void {
                    foreach (['event_type', 'brand', 'model', 'product_type', 'product_title', 'variant_title', 'installation_type', 'postal_code', 'service_zone', 'language', 'utm_source', 'utm_campaign'] as $column) {
                        $nested->orWhere($column, 'like', $search);
                    }
                });
            })
            ->when($filters['date_from'], fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['event_type'], fn (Builder $query, string $event) => $query->where('event_type', $event))
            ->when($filters['brand'] !== '', fn (Builder $query) => $query->where('brand', $filters['brand']))
            ->when($filters['model'] !== '', fn (Builder $query) => $query->where('model', $filters['model']))
            ->when($filters['product_type'] !== '', fn (Builder $query) => $query->where('product_type', $filters['product_type']))
            ->when($filters['installation'], fn (Builder $query, string $value) => $query->where('installation_selected', $value === 'yes'))
            ->when($filters['camera'], fn (Builder $query, string $value) => $query->where('camera_selected', $value === 'yes'))
            ->when($filters['zone'] !== '', fn (Builder $query) => $query->where('service_zone', $filters['zone']))
            ->when($filters['language'], fn (Builder $query, string $language) => $query->where('language', $language))
            ->when($filters['price_range'], function (Builder $query, string $range) use ($valueExpression): void {
                match ($range) {
                    '0-100' => $query->whereRaw("{$valueExpression} < 100"),
                    '100-250' => $query->whereRaw("{$valueExpression} >= 100 AND {$valueExpression} < 250"),
                    '250-500' => $query->whereRaw("{$valueExpression} >= 250 AND {$valueExpression} < 500"),
                    '500-1000' => $query->whereRaw("{$valueExpression} >= 500 AND {$valueExpression} < 1000"),
                    '1000+' => $query->whereRaw("{$valueExpression} >= 1000"),
                };
            });
    }

    private function analysis(Builder $query, string $type, string $requestedVisualization, string $countingMode): array
    {
        $effectiveCountingMode = $type === 'conversions' ? 'events' : $countingMode;
        $analysisQuery = $effectiveCountingMode === 'unique' ? $this->uniqueConfigurations($query) : clone $query;
        $defaultVisualization = in_array($type, ['timeline'], true) ? 'line' : (in_array($type, ['conversions', 'installations', 'cameras', 'languages'], true) ? 'pie' : 'bar');
        $visualization = $requestedVisualization === 'auto' ? $defaultVisualization : $requestedVisualization;
        $column = match ($type) {
            'brands' => 'brand', 'models' => 'model', 'years' => 'year',
            'products' => 'product_title', 'variants' => 'variant_title',
            'zones' => 'service_zone', 'languages' => 'language', default => null,
        };

        if ($column) {
            $data = (clone $analysisQuery)->whereNotNull($column)->where($column, '!=', '')
                ->selectRaw("{$column} AS label, COUNT(*) AS value")
                ->groupBy($column)->orderByDesc('value')->limit(20)->get();
        } elseif ($type === 'prices') {
            $data = (clone $analysisQuery)->selectRaw("CASE WHEN COALESCE(configuration_value, product_price, 0) < 100 THEN '0–99 €' WHEN COALESCE(configuration_value, product_price, 0) < 250 THEN '100–249 €' WHEN COALESCE(configuration_value, product_price, 0) < 500 THEN '250–499 €' WHEN COALESCE(configuration_value, product_price, 0) < 1000 THEN '500–999 €' ELSE '1.000 €+' END AS label, COUNT(*) AS value")
                ->groupBy('label')->orderByDesc('value')->get();
        } elseif ($type === 'installations' || $type === 'cameras') {
            $column = $type === 'installations' ? 'installation_selected' : 'camera_selected';
            $data = (clone $analysisQuery)->selectRaw("CASE WHEN {$column} = 1 THEN 'Sì' ELSE 'No' END AS label, COUNT(*) AS value")
                ->groupBy($column)->orderByDesc('value')->get();
        } elseif ($type === 'conversions') {
            $data = (clone $query)->selectRaw("CASE WHEN event_type = 'quote_downloaded' THEN 'Preventivi scaricati' ELSE 'Click su Proceder al pago' END AS label, COUNT(*) AS value")
                ->groupBy('event_type')->orderByDesc('value')->get();
        } else {
            $data = (clone $analysisQuery)->selectRaw('DATE(created_at) AS label, COUNT(*) AS value')
                ->groupByRaw('DATE(created_at)')->orderBy('label')->limit(90)->get();
        }

        return [
            'type' => $type,
            'visualization' => $visualization,
            'counting_mode' => $effectiveCountingMode,
            'data' => $data->map(fn ($item) => ['label' => (string) $item->label, 'value' => (float) $item->value])->values(),
        ];
    }

    private function insights(Builder $query, Builder $quoteQuery, array $filters): array
    {
        $total = (clone $query)->count();
        $uniqueQuery = $this->uniqueConfigurations($query);
        $uniqueTotal = (clone $uniqueQuery)->count();
        $quoteCount = (clone $quoteQuery)->count();
        $checkoutCount = (clone $query)->where('event_type', 'checkout_clicked')->count();
        $paymentProgressionRate = $quoteCount > 0 ? round(($checkoutCount / $quoteCount) * 100, 1) : 0;
        $installationCount = (clone $uniqueQuery)->where('installation_selected', true)->count();
        $average = (float) (clone $quoteQuery)->selectRaw('COALESCE(AVG(COALESCE(configuration_value, product_price, 0)), 0) AS aggregate')->value('aggregate');
        $insights = [
            'Marca più richiesta: '.($this->topValue($uniqueQuery, 'brand') ?? 'nessun dato'),
            'Modello più richiesto: '.($this->topValue($uniqueQuery, 'model') ?? 'nessun dato'),
            'Prodotto più richiesto: '.($this->topValue($uniqueQuery, 'product_title') ?? 'nessun dato'),
            'Valore medio dei preventivi: '.number_format($average, 2, ',', '.').' €',
            'Tasso di passaggio al pagamento: '.$paymentProgressionRate.'% (click su “Proceder al pago” rispetto ai preventivi scaricati)',
            'Configurazioni uniche con installazione: '.($uniqueTotal > 0 ? round(($installationCount / $uniqueTotal) * 100, 1) : 0).'%',
        ];

        if ($filters['date_from'] && $filters['date_to']) {
            $from = CarbonImmutable::parse($filters['date_from']);
            $to = CarbonImmutable::parse($filters['date_to']);
            $days = $from->diffInDays($to) + 1;
            $previousFilters = [...$filters, 'date_from' => $from->subDays($days)->toDateString(), 'date_to' => $from->subDay()->toDateString()];
            $previousCount = $this->filteredQuery($previousFilters)->count();
            $change = $previousCount > 0 ? round((($total - $previousCount) / $previousCount) * 100, 1) : null;
            $insights[] = $change === null
                ? 'Periodo precedente: nessun evento confrontabile'
                : 'Eventi rispetto al periodo precedente: '.($change >= 0 ? '+' : '').$change.'%'.(abs($change) >= 20 ? ' — variazione significativa' : '');
        }

        return $insights;
    }

    private function topValue(Builder|QueryBuilder $query, string $column): ?string
    {
        $result = (clone $query)->whereNotNull($column)->where($column, '!=', '')
            ->selectRaw("{$column}, COUNT(*) AS aggregate")
            ->groupBy($column)->orderByDesc('aggregate')->first();

        return $result?->{$column};
    }

    private function uniqueConfigurations(Builder $query): QueryBuilder
    {
        $identity = [
            'session_uuid', 'brand', 'model', 'year', 'product_id', 'variant_id',
            'installation_type', 'camera_selected',
        ];

        $unique = (clone $query)
            ->select($identity)
            ->selectRaw('MAX(product_title) AS product_title')
            ->selectRaw('MAX(variant_title) AS variant_title')
            ->selectRaw('MAX(product_price) AS product_price')
            ->selectRaw('MAX(configuration_value) AS configuration_value')
            ->selectRaw('MAX(installation_selected) AS installation_selected')
            ->selectRaw('MAX(service_zone) AS service_zone')
            ->selectRaw('MAX(language) AS language')
            ->selectRaw('MAX(created_at) AS created_at')
            ->groupBy($identity);

        return \DB::query()->fromSub($unique, 'unique_configurations');
    }

    private function dateFilter(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function allowedFilter(string $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }

    private function distinctValues(string $column): array
    {
        return ConfigurationStatistic::query()->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column)->all();
    }

    private function exportHeaders(): array
    {
        return ['Data/Ora', 'Evento', 'Marca', 'Modello', 'Anno', 'Tipo prodotto', 'Prodotto', 'Variante', 'Prezzo prodotto', 'Valore configurazione', 'Installazione', 'Tipo installazione', 'Camera', 'CAP', 'Zona', 'Lingua', 'Dispositivo'];
    }

    private function exportRow(ConfigurationStatistic $event): array
    {
        return [$event->created_at?->format('d/m/Y H:i:s'), $event->event_type, $event->brand, $event->model, $event->year, $event->product_type, $event->product_title, $event->variant_title, $event->product_price, $event->configuration_value, $event->installation_selected ? 'Sì' : 'No', $event->installation_type, $event->camera_selected ? 'Sì' : 'No', $event->postal_code, $event->service_zone, $event->language, $event->device_type];
    }
}
