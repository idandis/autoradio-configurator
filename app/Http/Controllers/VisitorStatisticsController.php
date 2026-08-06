<?php

namespace App\Http\Controllers;

use App\Models\ConfigurationStatistic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VisitorStatisticsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = [
            'date_from' => $this->date($request->string('date_from')->toString()),
            'date_to' => $this->date($request->string('date_to')->toString()),
            'country' => mb_substr(trim($request->string('country')->toString()), 0, 2),
            'device' => in_array($request->string('device')->toString(), ['desktop', 'tablet', 'mobile'], true)
                ? $request->string('device')->toString()
                : '',
        ];

        $allVisitors = ConfigurationStatistic::query()->where('event_type', 'configurator_entered');
        $query = $this->filteredQuery($filters);

        return Inertia::render('VisitorStatistics', [
            'visitors' => (clone $query)->latest()->paginate(50)->withQueryString(),
            'filters' => $filters,
            'countries' => (clone $allVisitors)->whereNotNull('country_code')->distinct()->orderBy('country_code')->pluck('country_code'),
            'stats' => [
                'total' => (clone $query)->count(),
                'today' => (clone $query)->whereDate('created_at', today())->count(),
                'last_7_days' => (clone $query)->where('created_at', '>=', now()->subDays(7))->count(),
                'last_30_days' => (clone $query)->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'analysis' => [
                'timeline' => $this->timeline($query),
                'countries' => $this->grouped($query, 'country_code'),
                'regions' => $this->grouped($query, 'region'),
                'cities' => $this->grouped($query, 'city'),
                'devices' => $this->grouped($query, 'device_type'),
                'languages' => $this->grouped($query, 'language'),
                'sources' => $this->sources($query),
            ],
        ]);
    }

    private function filteredQuery(array $filters): Builder
    {
        return ConfigurationStatistic::query()
            ->where('event_type', 'configurator_entered')
            ->when($filters['date_from'], fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['country'] !== '', fn (Builder $query) => $query->where('country_code', strtoupper($filters['country'])))
            ->when($filters['device'] !== '', fn (Builder $query) => $query->where('device_type', $filters['device']));
    }

    private function grouped(Builder $query, string $column): array
    {
        return (clone $query)->selectRaw("{$column} AS label, COUNT(*) AS value")
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderByDesc('value')
            ->limit(10)
            ->get()
            ->toArray();
    }

    private function timeline(Builder $query): array
    {
        return (clone $query)->selectRaw('DATE(created_at) AS label, COUNT(*) AS value')
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->groupByRaw('DATE(created_at)')
            ->orderBy('label')
            ->get()
            ->toArray();
    }

    private function sources(Builder $query): array
    {
        return (clone $query)->get(['referrer', 'utm_source'])
            ->map(function (ConfigurationStatistic $visitor): string {
                if ($visitor->utm_source) {
                    return $visitor->utm_source;
                }

                if (! $visitor->referrer) {
                    return 'Diretto';
                }

                return parse_url($visitor->referrer, PHP_URL_HOST) ?: 'Altro';
            })
            ->countBy()
            ->sortDesc()
            ->take(10)
            ->map(fn (int $value, string $label) => compact('label', 'value'))
            ->values()
            ->all();
    }

    private function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
