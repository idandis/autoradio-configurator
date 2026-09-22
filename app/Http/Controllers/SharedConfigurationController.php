<?php

namespace App\Http\Controllers;

use App\Models\SharedConfiguration;
use App\Services\ItalianCheckout;
use App\Services\QuoteNumbers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SharedConfigurationController extends Controller
{
    public function store(Request $request, ItalianCheckout $checkout, QuoteNumbers $quoteNumbers): JsonResponse
    {
        $validated = $request->validate([
            'configuration' => ['required', 'array'],
            'configuration.mode' => ['nullable', 'in:specific,universal'],
            'configuration.din' => ['nullable', 'in:1DIN,2DIN'],
            'configuration.brand' => ['nullable', 'string', 'max:255'],
            'configuration.model' => ['nullable', 'string', 'max:255'],
            'configuration.year' => ['nullable', 'integer', 'between:1900,2100'],
            'configuration.screens' => ['present', 'array'],
            'configuration.screens.*.product' => ['required', 'string', 'max:255'],
            'configuration.screens.*.variant' => ['required', 'string', 'max:255'],
            'configuration.cameras' => ['present', 'array'],
            'configuration.cameras.*' => ['string', 'max:255'],
            'configuration.speakers' => ['present', 'array'],
            'configuration.speakers.*' => ['string', 'max:255'],
            'configuration.customProducts' => ['present', 'array'],
            'configuration.customProducts.*' => ['string', 'max:255'],
            'configuration.quantities' => ['sometimes', 'array'],
            'configuration.quantities.*' => ['integer', 'min:1'],
            'configuration.importTotal' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'configuration.importCosts' => ['sometimes', 'array'],
            'configuration.importCosts.*' => ['numeric', 'min:0'],
            'configuration.installation' => ['nullable', 'string', 'max:255'],
            'configuration.postalCode' => ['nullable', 'string', 'max:5'],
            'configuration.serviceZone' => ['nullable', 'string', 'max:50'],
            'configuration.precheck' => ['nullable', 'string', 'max:50'],
            'checkout' => ['nullable', 'array:items,custom_discount,locale,import_amount'],
            'checkout.items' => ['required_with:checkout', 'array', 'min:1', 'max:50'],
            'checkout.import_amount' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
            'checkout.custom_discount' => ['nullable', 'array'],
            'checkout.locale' => ['required_with:checkout', 'in:it,es'],
            'reserve_quote_number' => ['sometimes', 'boolean'],
        ]);

        $storedCheckout = null;
        $fingerprint = null;
        if (isset($validated['checkout'])) {
            $locale = $validated['checkout']['locale'];
            $items = $checkout->normalizeItems($validated['checkout'], true, $locale);
            $discount = $checkout->normalizeDiscount($validated['checkout'], true, $locale);
            $importAmount = $checkout->normalizeImportAmount($validated['checkout'], true);
            $quote = $checkout->quote($items, locale: $locale, customDiscount: $discount, importAmount: $importAmount);
            $storedCheckout = compact('items', 'discount', 'locale', 'quote');
            $fingerprint = hash('sha256', json_encode([
                'configuration' => $validated['configuration'],
                'checkout' => $storedCheckout,
            ], JSON_THROW_ON_ERROR));
        }

        $attributes = [
            'uuid' => (string) Str::uuid(),
            'configuration' => $validated['configuration'],
            'checkout' => $storedCheckout,
        ];
        $sharedConfiguration = $fingerprint
            ? SharedConfiguration::firstOrCreate(['fingerprint' => $fingerprint], $attributes)
            : SharedConfiguration::create($attributes);

        return response()->json([
            'uuid' => $sharedConfiguration->uuid,
            'checkout_url' => $storedCheckout
                ? route('italian-checkout.shared', $sharedConfiguration->uuid, false)
                : null,
            'quote_number' => ($validated['reserve_quote_number'] ?? false)
                ? $quoteNumbers->next()
                : null,
        ], 201);
    }
}
