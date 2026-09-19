<?php

namespace App\Services;

use App\Models\ConfiguratorProduct;
use App\Models\ConfiguratorVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItalianCheckout
{
    public static function enabled(): bool
    {
        if (! config('italian_checkout.enabled')) {
            return false;
        }
        if (app()->environment('production')) {
            return StripePayments::live() && StripePayments::configured()
                && str_starts_with((string) config('stripe.webhook_secret'), 'whsec_');
        }

        return app()->environment('local', 'testing') && (! StripePayments::live() || app()->environment('testing'));
    }

    public static function availableForRequest(Request $request): bool
    {
        return self::enabled() && (! app()->environment('production')
            || in_array($request->getHost(), config('italian_checkout.hosts'), true));
    }

    /** @return array<int, array{type: string, id: int, quantity: int, import_unit_amount: int}> */
    public function normalizeItems(array $input, bool $allowCustomAmounts = false, string $locale = 'it'): array
    {
        $es = $locale === 'es';
        $data = Validator::make($input, [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:type,id,quantity,import_unit_amount'],
            'items.*.type' => ['required', Rule::in(['variant', 'product'])],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.import_unit_amount' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
        ], [
            'items.required' => $es ? 'Selecciona al menos un producto.' : 'Seleziona almeno un prodotto.',
            'items.*.quantity.*' => $es ? 'La cantidad debe estar entre 1 y 99.' : 'La quantità deve essere compresa tra 1 e 99.',
            'items.*.import_unit_amount.*' => $es ? 'El coste de importación no es válido.' : 'Il costo di importazione non è valido.',
        ])->validate();

        $items = [];
        foreach ($data['items'] as $item) {
            $importUnitAmount = (int) ($item['import_unit_amount'] ?? 0);
            if ($importUnitAmount > 0 && ! $allowCustomAmounts) {
                throw ValidationException::withMessages(['items' => $es ? 'Solo un administrador puede definir los costes de importación.' : 'Solo un amministratore può impostare i costi di importazione.']);
            }
            $key = $item['type'].'-'.$item['id'].'-'.$importUnitAmount;
            $quantity = ($items[$key]['quantity'] ?? 0) + (int) $item['quantity'];
            if ($quantity > 99) {
                throw ValidationException::withMessages(['items' => $es ? 'Máximo 99 unidades por producto.' : 'Massimo 99 unità per prodotto.']);
            }
            $items[$key] = [
                'type' => $item['type'],
                'id' => (int) $item['id'],
                'quantity' => $quantity,
                'import_unit_amount' => $importUnitAmount,
            ];
        }
        ksort($items);

        return array_values($items);
    }

    /** Read every price and description from the catalog; never accept browser amounts. */
    public function normalizeDiscount(array $input, bool $allowCustomAmounts = false, string $locale = 'it'): ?array
    {
        $es = $locale === 'es';
        $discount = Validator::make($input, [
            'custom_discount' => ['nullable', 'array:code,type,value'],
            'custom_discount.code' => ['required_with:custom_discount', 'string', 'max:100'],
            'custom_discount.type' => ['required_with:custom_discount', Rule::in(['percentage', 'fixed'])],
            'custom_discount.value' => ['required_with:custom_discount', 'integer', 'min:1', 'max:99999999'],
        ])->validate()['custom_discount'] ?? null;

        if ($discount && ! $allowCustomAmounts) {
            throw ValidationException::withMessages(['custom_discount' => $es ? 'Solo un administrador puede definir un descuento personalizado.' : 'Solo un amministratore può impostare uno sconto personalizzato.']);
        }
        if (($discount['type'] ?? null) === 'percentage' && $discount['value'] > 10000) {
            throw ValidationException::withMessages(['custom_discount' => $es ? 'El porcentaje de descuento no puede superar el 100%.' : 'La percentuale di sconto non può superare il 100%.']);
        }

        return $discount;
    }

    public function quote(array $items, bool $lock = false, string $locale = 'it', ?array $customDiscount = null): array
    {
        $locale = in_array($locale, ['it', 'es'], true) ? $locale : 'it';
        $lines = [];
        foreach ($items as $item) {
            $variant = null;
            if ($item['type'] === 'variant') {
                $variant = ConfiguratorVariant::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($item['id']);
                $productId = $variant?->configurator_product_id;
            } else {
                $productId = $item['id'];
            }
            $product = ConfiguratorProduct::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($productId);
            if (! $product || ! in_array($product->category, ['screen', 'camera', 'speaker'], true)) {
                throw ValidationException::withMessages(['items' => $locale === 'es' ? 'Un artículo ya no está disponible. Vuelve al configurador y actualiza la selección.' : 'Un articolo non è più acquistabile. Torna al configuratore e aggiorna la selezione.']);
            }
            if ($item['type'] === 'product' && $product->variants()->exists()) {
                throw ValidationException::withMessages(['items' => ($locale === 'es' ? 'Selecciona una variante para ' : 'Seleziona una variante per ').$product->localizedTitle($locale).'.']);
            }
            $price = $variant ? $variant->price : $product->price_min;
            if ($price === null || ! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', (string) $price)) {
                throw ValidationException::withMessages(['items' => ($locale === 'es' ? 'Precio no disponible para ' : 'Prezzo non disponibile per ').$product->localizedTitle($locale).'.']);
            }
            [$whole, $fraction] = array_pad(explode('.', (string) $price, 2), 2, '');
            $amount = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            if ($amount <= 0) {
                throw ValidationException::withMessages(['items' => ($locale === 'es' ? 'Precio no disponible para ' : 'Prezzo non disponibile per ').$product->localizedTitle($locale).'.']);
            }
            $importUnitAmount = (int) ($item['import_unit_amount'] ?? 0);
            $lines[] = [
                'product_handle' => $product->handle,
                'title' => $product->localizedTitle($locale),
                'variant_title' => $variant?->option_value ?: $variant?->title,
                'sku' => $variant?->sku,
                'quantity' => $item['quantity'],
                'unit_amount' => $amount,
                'import_unit_amount' => $importUnitAmount,
                'total_amount' => $amount * $item['quantity'],
                'import_total_amount' => $importUnitAmount * $item['quantity'],
            ];
        }

        $subtotal = array_sum(array_column($lines, 'total_amount'));
        $importAmount = array_sum(array_column($lines, 'import_total_amount'));
        // Same automatic tiers already shown by the configurator, calculated in cents.
        if ($customDiscount) {
            $discount = $customDiscount['type'] === 'percentage'
                ? intdiv($subtotal * (int) $customDiscount['value'] + 5000, 10000)
                : (int) $customDiscount['value'];
            $discount = min($subtotal, $discount);
            $value = $customDiscount['type'] === 'percentage'
                ? number_format($customDiscount['value'] / 100, 2, ',', '').'%'
                : number_format($customDiscount['value'] / 100, 2, ',', '.').' €';
            $label = ($locale === 'es' ? 'Descuento especial ' : 'Sconto speciale ').$value;
        } else {
            [$discount, $label] = match (true) {
                $subtotal >= 90000 => [5000, $locale === 'es' ? 'Descuento Vip' : 'Sconto Vip'],
                $subtotal >= 50000 => [intdiv($subtotal * 3 + 50, 100), $locale === 'es' ? 'Descuento Pro 3%' : 'Sconto Pro 3%'],
                $subtotal >= 30000 => [intdiv($subtotal * 2 + 50, 100), $locale === 'es' ? 'Descuento Base 2%' : 'Sconto Base 2%'],
                default => [0, null],
            };
        }
        $total = $subtotal + $importAmount - $discount;
        if ($total <= 0 || $total > 99999999) {
            throw ValidationException::withMessages(['items' => $locale === 'es' ? 'El total del presupuesto no es válido.' : 'Il totale del preventivo non è valido.']);
        }

        return [
            'items' => $lines,
            'subtotal_amount' => $subtotal,
            'import_amount' => $importAmount,
            'shipping_amount' => 0,
            'discount_amount' => $discount,
            'discount_label' => $label,
            'total_amount' => $total,
            'currency' => 'EUR',
        ];
    }

    public function fingerprint(array $quote): string
    {
        return hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
    }
}
