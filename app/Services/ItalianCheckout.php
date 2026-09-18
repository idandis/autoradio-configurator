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

    /** @return array<int, array{type: string, id: int, quantity: int}> */
    public function normalizeItems(array $input): array
    {
        $data = Validator::make($input, [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array:type,id,quantity'],
            'items.*.type' => ['required', Rule::in(['variant', 'product'])],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ], [
            'items.required' => 'Seleziona almeno un prodotto.',
            'items.*.quantity.*' => 'La quantità deve essere compresa tra 1 e 99.',
        ])->validate();

        $items = [];
        foreach ($data['items'] as $item) {
            $key = $item['type'].'-'.$item['id'];
            $quantity = ($items[$key]['quantity'] ?? 0) + (int) $item['quantity'];
            if ($quantity > 99) {
                throw ValidationException::withMessages(['items' => 'Massimo 99 unità per prodotto.']);
            }
            $items[$key] = ['type' => $item['type'], 'id' => (int) $item['id'], 'quantity' => $quantity];
        }
        ksort($items);

        return array_values($items);
    }

    /** Read every price and description from the catalog; never accept browser amounts. */
    public function quote(array $items, bool $lock = false): array
    {
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
                throw ValidationException::withMessages(['items' => 'Un articolo non è più acquistabile. Torna al configuratore e aggiorna la selezione.']);
            }
            if ($item['type'] === 'product' && $product->variants()->exists()) {
                throw ValidationException::withMessages(['items' => 'Seleziona una variante per '.$product->localizedTitle('it').'.']);
            }
            $price = $variant ? $variant->price : $product->price_min;
            if ($price === null || ! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', (string) $price)) {
                throw ValidationException::withMessages(['items' => 'Prezzo non disponibile per '.$product->localizedTitle('it').'.']);
            }
            [$whole, $fraction] = array_pad(explode('.', (string) $price, 2), 2, '');
            $amount = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            if ($amount <= 0) {
                throw ValidationException::withMessages(['items' => 'Prezzo non disponibile per '.$product->localizedTitle('it').'.']);
            }
            $lines[] = [
                'product_handle' => $product->handle,
                'title' => $product->localizedTitle('it'),
                'variant_title' => $variant?->option_value ?: $variant?->title,
                'sku' => $variant?->sku,
                'quantity' => $item['quantity'],
                'unit_amount' => $amount,
                'total_amount' => $amount * $item['quantity'],
            ];
        }

        $subtotal = array_sum(array_column($lines, 'total_amount'));
        // Same automatic tiers already shown by the configurator, calculated in cents.
        [$discount, $label] = match (true) {
            $subtotal >= 90000 => [5000, 'Sconto Vip'],
            $subtotal >= 50000 => [intdiv($subtotal * 3 + 50, 100), 'Sconto Pro 3%'],
            $subtotal >= 30000 => [intdiv($subtotal * 2 + 50, 100), 'Sconto Base 2%'],
            default => [0, null],
        };

        return [
            'items' => $lines,
            'subtotal_amount' => $subtotal,
            'shipping_amount' => 0,
            'discount_amount' => $discount,
            'discount_label' => $label,
            'total_amount' => $subtotal - $discount,
            'currency' => 'EUR',
        ];
    }

    public function fingerprint(array $quote): string
    {
        return hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
    }
}
