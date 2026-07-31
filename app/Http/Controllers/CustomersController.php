<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerCost;
use App\Models\CustomerContact;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderRefund;
use App\Models\CustomerOrderTransaction;
use App\Models\CustomerSupplierRefund;
use App\Services\CustomerCsvImporter;
use App\Services\CurrencyConverter;
use App\Services\MatrixifyOrderImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class CustomersController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim($request->string('search')->toString());
        $attention = $request->string('attention')->toString();
        if (! in_array($attention, ['colored', 'green', 'yellow', 'red'], true)) {
            $attention = '';
        }
        $sort = $request->string('sort')->toString() ?: 'last_order_at';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSorts = [
            'customer', 'contacts', 'location', 'orders', 'first_order_at',
            'last_order_at', 'vehicle', 'service', 'spent', 'refunds', 'adjustments', 'refund_date',
            'net_spent', 'latest_purchase', 'costs', 'total_costs',
            'supplier_refunds', 'total_supplier_refunds', 'notes',
        ];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'last_order_at';
        }
        $refundDateExpression = <<<'SQL'
COALESCE(
    (
        SELECT MAX(customer_order_transactions.processed_at)
        FROM customer_order_transactions
        INNER JOIN customer_orders
            ON customer_orders.id = customer_order_transactions.customer_order_id
        WHERE customer_orders.customer_id = customers.id
            AND customer_order_transactions.kind = 'refund'
            AND customer_order_transactions.status = 'success'
            AND customer_order_transactions.amount > 0
    ),
    (
        SELECT MAX(customer_order_refunds.created_at_shopify)
        FROM customer_order_refunds
        INNER JOIN customer_orders
            ON customer_orders.id = customer_order_refunds.customer_order_id
        WHERE customer_orders.customer_id = customers.id
            AND customer_orders.total_refund > 0
    )
)
SQL;

        $customers = Customer::query()
            ->withCount('orders')
            ->withSum('orders as imported_total', 'current_total')
            ->withSum('orders as refunded_total', 'total_refund')
            ->withSum('costs as costs_total', 'amount_eur')
            ->withSum('supplierRefunds as supplier_refunds_total', 'amount_eur')
            ->addSelect([
                'paid_transactions_total' => CustomerOrderTransaction::query()
                    ->selectRaw('COALESCE(SUM(customer_order_transactions.amount), 0)')
                    ->join('customer_orders', 'customer_orders.id', '=', 'customer_order_transactions.customer_order_id')
                    ->whereColumn('customer_orders.customer_id', 'customers.id')
                    ->whereIn('customer_order_transactions.kind', ['sale', 'capture'])
                    ->where('customer_order_transactions.status', 'success'),
                'refund_transactions_total' => CustomerOrderTransaction::query()
                    ->selectRaw('COALESCE(SUM(customer_order_transactions.amount), 0)')
                    ->join('customer_orders', 'customer_orders.id', '=', 'customer_order_transactions.customer_order_id')
                    ->whereColumn('customer_orders.customer_id', 'customers.id')
                    ->where('customer_order_transactions.kind', 'refund')
                    ->where('customer_order_transactions.status', 'success'),
                'last_refund_at' => CustomerOrderRefund::query()
                    ->selectRaw('MAX(customer_order_refunds.created_at_shopify)')
                    ->join('customer_orders', 'customer_orders.id', '=', 'customer_order_refunds.customer_order_id')
                    ->whereColumn('customer_orders.customer_id', 'customers.id')
                    ->where('customer_orders.total_refund', '>', 0),
                'last_refund_transaction_at' => CustomerOrderTransaction::query()
                    ->selectRaw('MAX(customer_order_transactions.processed_at)')
                    ->join('customer_orders', 'customer_orders.id', '=', 'customer_order_transactions.customer_order_id')
                    ->whereColumn('customer_orders.customer_id', 'customers.id')
                    ->where('customer_order_transactions.kind', 'refund')
                    ->where('customer_order_transactions.status', 'success')
                    ->where('customer_order_transactions.amount', '>', 0),
            ])
            ->with([
                'addresses' => fn ($query) => $query->orderByDesc('is_default'),
                'contacts' => fn ($query) => $query->orderBy('type')->orderBy('id'),
                'latestOrder.lines:id,customer_order_id,title,sku,quantity,price,total,fulfillment_status',
                'orders.lines:id,customer_order_id,title,product_handle,fulfillment_status',
                'orders.lines.configuratorProduct:id,handle,brand,model,year_from,year_to',
                'costs:id,customer_id,description,amount,currency,exchange_rate,amount_eur',
                'supplierRefunds:id,customer_id,description,amount,currency,exchange_rate,amount_eur',
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $tokens = preg_split('/\s+/u', Str::lower(Str::ascii($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';

                    $query->where(function (Builder $nested) use ($like): void {
                        foreach (['first_name', 'last_name', 'email', 'phone', 'shopify_id', 'note', 'tags'] as $column) {
                            $nested->orWhereRaw($this->searchableText($column).' LIKE ?', [$like]);
                        }

                        $nested->orWhereHas('addresses', function (Builder $addressQuery) use ($like): void {
                            $addressQuery->where(function (Builder $addressFields) use ($like): void {
                                foreach (['first_name', 'last_name', 'company', 'line_1', 'line_2', 'city', 'province', 'country', 'zip', 'phone'] as $column) {
                                    $addressFields->orWhereRaw($this->searchableText($column).' LIKE ?', [$like]);
                                }
                            });
                        });
                        $nested->orWhereHas('contacts', function (Builder $contactQuery) use ($like): void {
                            $contactQuery->whereRaw($this->searchableText('value').' LIKE ?', [$like]);
                        });
                    });
                }
            })
            ->when(
                $attention === 'colored',
                fn (Builder $query) => $query->whereIn('attention_color', ['green', 'yellow', 'red']),
            )
            ->when(
                in_array($attention, ['green', 'yellow', 'red'], true),
                fn (Builder $query) => $query->where('attention_color', $attention),
            )
            ->when($sort === 'customer', fn (Builder $query) => $query
                ->orderBy('last_name', $direction)->orderBy('first_name', $direction))
            ->when($sort === 'contacts', fn (Builder $query) => $query->orderBy('email', $direction))
            ->when($sort === 'location', fn (Builder $query) => $query->orderBy(
                \App\Models\CustomerAddress::select('city')
                    ->whereColumn('customer_addresses.customer_id', 'customers.id')
                    ->orderByDesc('is_default')
                    ->limit(1),
                $direction,
            ))
            ->when($sort === 'orders', fn (Builder $query) => $query->orderBy('total_orders', $direction))
            ->when($sort === 'first_order_at', fn (Builder $query) => $query->orderBy('first_order_at', $direction))
            ->when(in_array($sort, ['last_order_at', 'latest_purchase'], true), fn (Builder $query) => $query->orderBy('last_order_at', $direction))
            ->when($sort === 'vehicle', fn (Builder $query) => $query->orderBy(
                \App\Models\ConfiguratorProduct::select('brand')
                    ->join('customer_order_lines', 'customer_order_lines.product_handle', '=', 'configurator_products.handle')
                    ->join('customer_orders', 'customer_orders.id', '=', 'customer_order_lines.customer_order_id')
                    ->whereColumn('customer_orders.customer_id', 'customers.id')
                    ->whereNotNull('configurator_products.brand')
                    ->limit(1),
                $direction,
            ))
            ->when($sort === 'service', fn (Builder $query) => $query
                ->orderByRaw(
                    "EXISTS (
                        SELECT 1 FROM customer_order_lines
                        INNER JOIN customer_orders ON customer_orders.id = customer_order_lines.customer_order_id
                        WHERE customer_orders.customer_id = customers.id
                        AND LOWER(customer_order_lines.title) LIKE '%instalaci%'
                    ) {$direction}"
                )
                ->orderBy('last_order_at', 'desc'))
            ->when($sort === 'spent', fn (Builder $query) => $query->orderBy('paid_transactions_total', $direction))
            ->when($sort === 'net_spent', fn (Builder $query) => $query->orderByRaw(
                "(
                    (SELECT COALESCE(SUM(current_total), 0) FROM customer_orders WHERE customer_id = customers.id)
                    -
                    (SELECT COALESCE(SUM(amount_eur), 0) FROM customer_costs WHERE customer_id = customers.id)
                    +
                    (SELECT COALESCE(SUM(amount_eur), 0) FROM customer_supplier_refunds WHERE customer_id = customers.id)
                ) {$direction}"
            ))
            ->when($sort === 'refunds', fn (Builder $query) => $query->orderBy('refunded_total', $direction))
            ->when($sort === 'adjustments', fn (Builder $query) => $query->orderBy('imported_total', $direction))
            ->when($sort === 'refund_date', fn (Builder $query) => $query
                ->orderByRaw("({$refundDateExpression}) IS NULL")
                ->orderByRaw("{$refundDateExpression} {$direction}"))
            ->when($sort === 'notes', fn (Builder $query) => $query->orderBy('note', $direction))
            ->when($sort === 'total_costs', fn (Builder $query) => $query->orderBy('costs_total', $direction))
            ->when($sort === 'costs', fn (Builder $query) => $query->orderBy('costs_total', $direction))
            ->when(in_array($sort, ['supplier_refunds', 'total_supplier_refunds'], true), fn (Builder $query) => $query->orderBy('supplier_refunds_total', $direction))
            ->orderBy('id')
            ->orderBy('last_name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Customer $customer) => [
                'id' => $customer->id,
                'shopify_id' => $customer->shopify_id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'contacts' => $customer->contacts->map(fn (CustomerContact $contact) => [
                    'id' => $contact->id,
                    'type' => $contact->type,
                    'value' => $contact->value,
                ])->values(),
                'language' => $customer->language,
                'state' => $customer->state,
                'tags' => $customer->tags,
                'note' => $customer->note,
                'attention_color' => $customer->attention_color,
                'total_orders' => $customer->total_orders,
                'imported_orders' => $customer->orders_count,
                'total_spent' => $customer->total_spent,
                'imported_total' => $customer->imported_total ?? 0,
                'paid_total' => (float) $customer->paid_transactions_total > 0
                    ? (float) $customer->paid_transactions_total
                    : (float) ($customer->imported_total ?? 0),
                'refunded_total' => max(
                    (float) ($customer->refund_transactions_total ?? 0),
                    (float) ($customer->refunded_total ?? 0),
                ),
                'adjustment_total' => max(
                    0,
                    ((float) $customer->paid_transactions_total > 0
                        ? (float) $customer->paid_transactions_total
                        : (float) ($customer->imported_total ?? 0))
                    - max(
                        (float) ($customer->refund_transactions_total ?? 0),
                        (float) ($customer->refunded_total ?? 0),
                    )
                    - (float) ($customer->imported_total ?? 0),
                ),
                'net_total' => (float) ($customer->imported_total ?? 0)
                    - (float) ($customer->costs_total ?? 0)
                    + (float) ($customer->supplier_refunds_total ?? 0),
                'last_refund_at' => max(
                    (float) ($customer->refund_transactions_total ?? 0),
                    (float) ($customer->refunded_total ?? 0),
                ) > 0 && ($customer->last_refund_transaction_at || $customer->last_refund_at)
                    ? \Carbon\Carbon::parse(
                        $customer->last_refund_transaction_at ?: $customer->last_refund_at
                    )->toIso8601String()
                    : null,
                'first_order_at' => $customer->first_order_at?->toIso8601String(),
                'last_order_at' => $customer->last_order_at?->toIso8601String(),
                'latest_order' => $this->latestOrderPayload($customer->latestOrder),
                'service' => $this->serviceProfile($customer),
                'vehicles' => $this->customerVehicles($customer),
                'costs' => $customer->costs->map(fn (CustomerCost $cost) => [
                    'id' => $cost->id,
                    'description' => $cost->description,
                    'amount' => $cost->amount,
                    'currency' => $cost->currency,
                    'exchange_rate' => $cost->exchange_rate,
                    'amount_eur' => $cost->amount_eur,
                ])->values(),
                'costs_total' => $customer->costs_total ?? 0,
                'supplier_refunds' => $customer->supplierRefunds->map(fn (CustomerSupplierRefund $refund) => [
                    'id' => $refund->id,
                    'description' => $refund->description,
                    'amount' => $refund->amount,
                    'currency' => $refund->currency,
                    'exchange_rate' => $refund->exchange_rate,
                    'amount_eur' => $refund->amount_eur,
                ])->values(),
                'supplier_refunds_total' => $customer->supplier_refunds_total ?? 0,
                'address' => $customer->addresses->first()
                    ? [
                        'line_1' => $customer->addresses->first()->line_1,
                        'city' => $customer->addresses->first()->city,
                        'province' => $customer->addresses->first()->province,
                        'country' => $customer->addresses->first()->country,
                        'zip' => $customer->addresses->first()->zip,
                    ]
                    : null,
            ]);

        return Inertia::render('Customers', [
            'customers' => $customers,
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
                'attention' => $attention,
            ],
            'columnOrder' => $request->user()?->customer_column_order,
            'flashStatus' => session('status'),
            'stats' => [
                'customers' => Customer::count(),
                'orders' => Customer::sum('total_orders'),
                'imported_orders' => CustomerOrder::count(),
                'spent' => Customer::sum('total_spent'),
                'refunded' => max(
                    (float) CustomerOrder::sum('total_refund'),
                    (float) CustomerOrderTransaction::where('kind', 'refund')
                        ->where('status', 'success')
                        ->sum('amount'),
                ),
            ],
        ]);
    }

    private function searchableText(string $column): string
    {
        $expression = "LOWER(COALESCE({$column}, ''))";
        $characters = [
            'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Ã' => 'a', 'Å' => 'a',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'Õ' => 'o',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ñ' => 'n', 'ñ' => 'n', 'Ç' => 'c', 'ç' => 'c',
        ];

        foreach ($characters as $character => $replacement) {
            $expression = "REPLACE({$expression}, '{$character}', '{$replacement}')";
        }

        return $expression;
    }

    private function serviceProfile(Customer $customer): array
    {
        $titles = $customer->orders
            ->flatMap(fn (CustomerOrder $order) => $order->lines->pluck('title'))
            ->filter()
            ->unique()
            ->values();
        $installationTitles = $titles
            ->filter(fn (string $title) => str_contains(mb_strtolower($title), 'instalaci'))
            ->values();
        $hasScreen = $titles->contains(function (string $title): bool {
            $normalized = mb_strtolower($title);

            return str_contains($normalized, 'pantalla')
                || str_contains($normalized, 'autoradio');
        });
        $hasCamera = $titles->contains(
            fn (string $title) => str_contains(mb_strtolower($title), 'cámara')
                || str_contains(mb_strtolower($title), 'camara')
        );

        if ($installationTitles->isNotEmpty()) {
            $label = match (true) {
                $hasScreen && $hasCamera => 'Installazione + pantalla + camera',
                $hasScreen => 'Installazione + pantalla',
                $hasCamera => 'Installazione + camera',
                default => 'Con installazione',
            };

            return [
                'key' => 'installation',
                'label' => $label,
                'details' => $installationTitles->implode(' · '),
            ];
        }

        if ($hasScreen) {
            return [
                'key' => 'screen',
                'label' => 'Solo pantalla',
                'details' => $hasCamera ? 'Pantalla con camera, senza installazione' : 'Senza installazione',
            ];
        }

        if ($hasCamera) {
            return [
                'key' => 'camera',
                'label' => 'Solo camera',
                'details' => 'Senza installazione',
            ];
        }

        return [
            'key' => 'product',
            'label' => 'Solo prodotto',
            'details' => $titles->first() ?: 'Nessun prodotto disponibile',
        ];
    }

    private function latestOrderPayload(?CustomerOrder $order): ?array
    {
        if (! $order) {
            return null;
        }

        $cancelledLineIds = $this->cancelledLineIds($order);

        return [
            'name' => $order->name,
            'total' => $order->total,
            'current_total' => $order->current_total,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'products' => $order->lines->map(fn ($line) => [
                'title' => $line->title,
                'sku' => $line->sku,
                'quantity' => $line->quantity,
                'price' => $line->price,
                'total' => $line->total,
                'cancelled' => in_array($line->id, $cancelledLineIds, true),
            ])->values(),
        ];
    }

    /**
     * Matrixify keeps both the old and replacement line after some Shopify edits.
     * When no explicit status is available, match the order reduction and prefer
     * the oldest compatible lines, leaving the newest replacement active.
     */
    private function cancelledLineIds(CustomerOrder $order): array
    {
        if ($order->cancelled_at !== null) {
            return $order->lines->pluck('id')->all();
        }

        $explicit = $order->lines
            ->filter(fn ($line) => mb_strtolower((string) $line->fulfillment_status) === 'restocked')
            ->pluck('id')
            ->all();
        $reduction = round(max(0, (float) $order->total - (float) $order->current_total), 2);

        if ($reduction <= 0.01 || (float) $order->total_line_items <= 0) {
            return $explicit;
        }

        $factor = max(
            0,
            ((float) $order->total - (float) $order->total_shipping) / (float) $order->total_line_items,
        );
        $explicitReduction = $order->lines
            ->whereIn('id', $explicit)
            ->sum(fn ($line) => (float) $line->total * $factor);
        $target = round($reduction - $explicitReduction, 2);
        $candidates = $order->lines
            ->whereNotIn('id', $explicit)
            ->sortBy('id')
            ->values();

        if ($target <= 0.01 || $candidates->isEmpty() || $candidates->count() > 18) {
            return $explicit;
        }

        $amounts = $candidates
            ->map(fn ($line) => round((float) $line->total * $factor, 2))
            ->all();
        $matchedIndexes = $this->matchingOldestSubset($amounts, $target);

        if ($matchedIndexes === null) {
            return $explicit;
        }

        return array_values(array_unique([
            ...$explicit,
            ...array_map(fn (int $index) => $candidates[$index]->id, $matchedIndexes),
        ]));
    }

    private function matchingOldestSubset(
        array $amounts,
        float $target,
        int $index = 0,
        float $sum = 0,
        array $selected = [],
    ): ?array {
        if (abs($sum - $target) <= 0.02) {
            return $selected;
        }

        if ($index >= count($amounts) || $sum > $target + 0.02) {
            return null;
        }

        $withOldest = $this->matchingOldestSubset(
            $amounts,
            $target,
            $index + 1,
            $sum + $amounts[$index],
            [...$selected, $index],
        );

        return $withOldest ?? $this->matchingOldestSubset(
            $amounts,
            $target,
            $index + 1,
            $sum,
            $selected,
        );
    }

    private function customerVehicles(Customer $customer): array
    {
        return $customer->orders
            ->flatMap(fn (CustomerOrder $order) => $order->lines)
            ->map(fn ($line) => $line->configuratorProduct)
            ->filter(function ($product): bool {
                if (! $product || (! $product->brand && ! $product->model)) {
                    return false;
                }

                $genericValues = [
                    'universal',
                    'universale',
                    'todo vehículo',
                    'todo vehiculo',
                ];
                $brand = mb_strtolower(trim((string) $product->brand));
                $model = mb_strtolower(trim((string) $product->model));

                return ! in_array($brand, $genericValues, true)
                    && ! in_array($model, $genericValues, true)
                    && ! str_contains($brand, 'universal')
                    && ! str_contains($model, 'universal');
            })
            ->map(fn ($product) => [
                'brand' => $product->brand,
                'model' => $product->model,
                'year_from' => $product->year_from,
                'year_to' => $product->year_to,
            ])
            ->unique(fn (array $vehicle) => implode('|', $vehicle))
            ->values()
            ->all();
    }

    public function updateNote(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $customer->update([
            'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
        ]);

        return back()->with('status', 'Nota cliente salvata.');
    }

    public function storeContact(Request $request, Customer $customer): RedirectResponse
    {
        $customer->contacts()->create($this->validatedContact($request));

        return back()->with('status', 'Contatto aggiunto.');
    }

    public function updateContact(
        Request $request,
        Customer $customer,
        CustomerContact $contact,
    ): RedirectResponse {
        abort_unless($contact->customer_id === $customer->id, 404);
        $contact->update($this->validatedContact($request));

        return back()->with('status', 'Contatto aggiornato.');
    }

    public function destroyContact(Customer $customer, CustomerContact $contact): RedirectResponse
    {
        abort_unless($contact->customer_id === $customer->id, 404);
        $contact->delete();

        return back()->with('status', 'Contatto eliminato.');
    }

    private function validatedContact(Request $request): array
    {
        $validated = $request->validate([
            'type' => ['required', 'in:email,phone,note'],
            'value' => ['required', 'string', 'max:2000'],
        ]);

        $validated['value'] = trim($validated['value']);

        if ($validated['type'] === 'email') {
            validator($validated, ['value' => ['email:rfc', 'max:255']])->validate();
        }

        return $validated;
    }

    public function updateAttentionColor(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'attention_color' => ['nullable', 'in:green,yellow,red'],
        ]);

        $customer->update([
            'attention_color' => $validated['attention_color'] ?? null,
        ]);

        return back();
    }

    public function updateColumnOrder(Request $request): RedirectResponse
    {
        $allowed = [
            'customer', 'contacts', 'location', 'orders', 'first_order_at', 'last_order_at',
            'vehicle', 'service', 'spent', 'refunds', 'adjustments', 'refund_date',
            'latest_purchase', 'costs', 'total_costs', 'supplier_refunds',
            'total_supplier_refunds', 'notes', 'net_spent',
        ];
        $validated = $request->validate([
            'columns' => ['required', 'array'],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', $allowed)],
        ]);
        $columns = array_values(array_filter(
            $validated['columns'],
            fn (string $column) => $column !== 'net_spent',
        ));
        $columns = array_values(array_unique([...$columns, ...array_diff($allowed, $columns)]));
        $columns = array_values(array_filter(
            $columns,
            fn (string $column) => $column !== 'net_spent',
        ));
        $columns[] = 'net_spent';

        $request->user()->update(['customer_column_order' => $columns]);

        return back();
    }

    public function storeCost(
        Request $request,
        Customer $customer,
        CurrencyConverter $converter,
    ): RedirectResponse
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['required', 'in:EUR,USD'],
        ]);

        try {
            $customer->costs()->create($this->costValues($validated, $converter));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['cost' => $exception->getMessage()]);
        }

        return back()->with('status', 'Costo cliente aggiunto.');
    }

    public function updateCost(
        Request $request,
        Customer $customer,
        CustomerCost $cost,
        CurrencyConverter $converter,
    ): RedirectResponse {
        abort_unless($cost->customer_id === $customer->id, 404);
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['required', 'in:EUR,USD'],
        ]);
        try {
            $cost->update($this->costValues($validated, $converter));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['cost' => $exception->getMessage()]);
        }

        return back()->with('status', 'Costo cliente aggiornato.');
    }

    public function destroyCost(Customer $customer, CustomerCost $cost): RedirectResponse
    {
        abort_unless($cost->customer_id === $customer->id, 404);
        $cost->delete();

        return back()->with('status', 'Costo cliente eliminato.');
    }

    public function storeSupplierRefund(
        Request $request,
        Customer $customer,
        CurrencyConverter $converter,
    ): RedirectResponse {
        $validated = $this->validateMoneyEntry($request);

        try {
            $customer->supplierRefunds()->create($this->costValues($validated, $converter));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['supplier_refund' => $exception->getMessage()]);
        }

        return back()->with('status', 'Rimborso da fornitore aggiunto.');
    }

    public function updateSupplierRefund(
        Request $request,
        Customer $customer,
        CustomerSupplierRefund $supplierRefund,
        CurrencyConverter $converter,
    ): RedirectResponse {
        abort_unless($supplierRefund->customer_id === $customer->id, 404);
        $validated = $this->validateMoneyEntry($request);

        try {
            $supplierRefund->update($this->costValues($validated, $converter));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['supplier_refund' => $exception->getMessage()]);
        }

        return back()->with('status', 'Rimborso da fornitore aggiornato.');
    }

    public function destroySupplierRefund(
        Customer $customer,
        CustomerSupplierRefund $supplierRefund,
    ): RedirectResponse {
        abort_unless($supplierRefund->customer_id === $customer->id, 404);
        $supplierRefund->delete();

        return back()->with('status', 'Rimborso da fornitore eliminato.');
    }

    private function validateMoneyEntry(Request $request): array
    {
        return $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['required', 'in:EUR,USD'],
        ]);
    }

    private function costValues(array $validated, CurrencyConverter $converter): array
    {
        $rate = $validated['currency'] === 'USD'
            ? $converter->usdToEurRate()
            : 1;

        return [
            'description' => trim($validated['description']),
            'amount' => round((float) $validated['amount'], 2),
            'currency' => $validated['currency'],
            'exchange_rate' => $rate,
            'amount_eur' => round((float) $validated['amount'] * $rate, 2),
        ];
    }

    public function importOrders(Request $request, MatrixifyOrderImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'orders' => ['required', 'file', 'mimes:csv,txt,xls,xlsx'],
        ]);

        try {
            $stats = $importer->import($validated['orders']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['orders' => $exception->getMessage()]);
        }

        return back()->with('status', sprintf(
            'Ordini importati: %d nuovi, %d aggiornati. Clienti: %d nuovi, %d aggiornati. Prodotti ordine: %d.',
            $stats['orders_imported'],
            $stats['orders_updated'],
            $stats['customers_imported'],
            $stats['customers_updated'],
            $stats['lines'],
        ));
    }

    public function import(
        Request $request,
        CustomerCsvImporter $customerImporter,
        MatrixifyOrderImporter $orderImporter,
    ): RedirectResponse
    {
        $validated = $request->validate([
            'customers' => ['required', 'file', 'mimes:csv,txt,xls,xlsx'],
        ]);

        try {
            $stats = $orderImporter->import($validated['customers']);

            return back()->with('status', sprintf(
                'Import clienti da Orders completato: %d ordini nuovi, %d aggiornati; %d clienti nuovi, %d aggiornati.',
                $stats['orders_imported'],
                $stats['orders_updated'],
                $stats['customers_imported'],
                $stats['customers_updated'],
            ));
        } catch (RuntimeException $orderException) {
            try {
                $stats = $customerImporter->import($validated['customers']);
            } catch (RuntimeException $customerException) {
                return back()->withErrors([
                    'customers' => 'File non riconosciuto. Usa un export Matrixify Orders oppure Customers. '.$orderException->getMessage(),
                ]);
            }
        }

        return back()->with('status', sprintf(
            'Import completato: %d nuovi clienti, %d aggiornati, %d indirizzi. Esclusi con spesa zero: %d.',
            $stats['imported'],
            $stats['updated'],
            $stats['addresses'],
            $stats['skipped_without_spending'],
        ));
    }
}
