<?php

namespace App\Http\Controllers;

use App\Models\ItalianOrder;
use App\Models\SharedConfiguration;
use App\Services\ItalianCheckout;
use App\Services\StripePayments;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;

class ItalianCheckoutController extends Controller
{
    public function shared(Request $request, string $uuid, ItalianCheckout $checkout): RedirectResponse
    {
        abort_unless(ItalianCheckout::availableForRequest($request), 404);
        $shared = SharedConfiguration::where('uuid', $uuid)->firstOrFail();
        $saved = $shared->checkout;
        abort_unless(is_array($saved)
            && is_array($saved['items'] ?? null)
            && is_array($saved['quote'] ?? null)
            && in_array($saved['locale'] ?? null, ['it', 'es'], true), 404);

        $request->session()->put("italian_checkout_drafts.$uuid", [
            'items' => $saved['items'],
            'discount' => $saved['discount'] ?? null,
            'locale' => $saved['locale'],
            'origin' => $request->getSchemeAndHttpHost(),
            'quote_hash' => $checkout->fingerprint($saved['quote']),
            'locked_quote' => $saved['quote'],
        ]);

        return $this->redirectTo('italian-checkout.show', $uuid);
    }

    public function start(Request $request, ItalianCheckout $checkout): RedirectResponse
    {
        abort_unless(ItalianCheckout::availableForRequest($request), 404);
        $allowCustomAmounts = (bool) $request->user()?->is_admin;
        $requestedLocale = $request->input('locale', $request->query('lang'));
        $locale = in_array($request->getHost(), ['autoradiocanario.com', 'www.autoradiocanario.com', 'config.autoradiocanario.com'], true)
            || $requestedLocale === 'es' ? 'es' : 'it';
        $items = $checkout->normalizeItems($request->all(), $allowCustomAmounts, $locale);
        $discount = $checkout->normalizeDiscount($request->all(), $allowCustomAmounts, $locale);
        $importAmount = $checkout->normalizeImportAmount($request->all(), $allowCustomAmounts);
        $quote = $checkout->quote($items, locale: $locale, customDiscount: $discount, importAmount: $importAmount);
        $drafts = $request->session()->get('italian_checkout_drafts', []);
        // Repeated clicks for the same active cart reuse the same idempotency token.
        foreach ($drafts as $token => $draft) {
            if ($draft['items'] === $items
                && ($draft['import_amount'] ?? 0) === $importAmount
                && ($draft['discount'] ?? null) === $discount
                && ($draft['locale'] ?? 'it') === $locale
                && ! ItalianOrder::withTrashed()->where('checkout_token', $token)->exists()) {
                return $this->redirectTo('italian-checkout.show', $token);
            }
        }
        $token = (string) Str::uuid();
        $drafts[$token] = [
            'items' => $items,
            'discount' => $discount,
            'import_amount' => $importAmount,
            'locale' => $locale,
            'origin' => $request->getSchemeAndHttpHost(),
            'quote_hash' => $checkout->fingerprint($quote),
        ];
        $request->session()->put('italian_checkout_drafts', array_slice($drafts, -20, null, true));

        return $this->redirectTo('italian-checkout.show', $token);
    }

    public function show(Request $request, string $token, ItalianCheckout $checkout): Response|RedirectResponse
    {
        $draft = $this->draft($request, $token);
        if ($existing = ItalianOrder::where('checkout_token', $token)->first()) {
            return $this->paymentDestination($existing, $token);
        }
        $quote = null;
        $unavailable = null;
        $changed = false;
        if (isset($draft['locked_quote'])) {
            $quote = $draft['locked_quote'];
        } else {
            try {
                $quote = $checkout->quote($draft['items'], locale: $draft['locale'], customDiscount: $draft['discount'], importAmount: $draft['import_amount'] ?? 0);
                $hash = $checkout->fingerprint($quote);
                $changed = $hash !== $draft['quote_hash'];
                $request->session()->put("italian_checkout_drafts.$token.quote_hash", $hash);
            } catch (ValidationException $exception) {
                $unavailable = $exception->validator->errors()->first();
            }
        }

        return Inertia::render('ItalianCheckout/Checkout', [
            'token' => $token,
            'quote' => $quote,
            'quoteHash' => $quote ? $checkout->fingerprint($quote) : null,
            'changed' => $changed,
            'unavailable' => $unavailable,
            'isTest' => ! StripePayments::live(),
            'checkoutLocale' => $draft['locale'],
        ]);
    }

    public function store(Request $request, string $token, ItalianCheckout $checkout): RedirectResponse
    {
        $draft = $this->draft($request, $token);
        if ($existing = ItalianOrder::where('checkout_token', $token)->first()) {
            return $this->paymentDestination($existing, $token);
        }
        $country = $draft['locale'] === 'es' ? 'ES' : 'IT';
        if (is_string($request->input('province'))) {
            $province = trim($request->input('province'));
            $request->merge(['province' => $country === 'IT' ? mb_strtoupper($province) : $province]);
        }
        $es = $country === 'ES';
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[+\d\s().\/-]{5,40}$/'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            'city' => ['required', 'string', 'max:100'],
            'province' => $country === 'IT'
                ? ['required', 'string', 'regex:/^[A-Z]{2}$/']
                : ['required', 'string', 'min:2', 'max:100'],
            'country' => ['required', Rule::in([$country])],
            'reviewed' => ['accepted'],
            'quote_hash' => ['required', 'string', 'size:64'],
        ], [
            'required' => $es ? 'Completa este campo.' : 'Compila questo campo.',
            'email.email' => $es ? 'Introduce un email válido.' : 'Inserisci un indirizzo email valido.',
            'phone.regex' => $es ? 'Introduce un número de teléfono válido.' : 'Inserisci un numero di telefono valido.',
            'postal_code.regex' => $es ? 'El código postal debe contener 5 cifras.' : 'Il CAP deve contenere 5 cifre.',
            'province.regex' => 'Indica la sigla della provincia, ad esempio RM.',
            'country.in' => $es ? 'La entrega está disponible solo en España.' : 'La spedizione è disponibile solo in Italia.',
            'reviewed.accepted' => $es ? 'Confirma que has comprobado los datos del pedido.' : 'Conferma di aver verificato i dati dell’ordine.',
        ]);

        try {
            DB::transaction(function () use ($data, $draft, $token, $checkout, $country) {
                if (ItalianOrder::where('checkout_token', $token)->exists()) {
                    return;
                }
                $quote = $draft['locked_quote'] ?? $checkout->quote($draft['items'], lock: true, locale: $draft['locale'], customDiscount: $draft['discount'], importAmount: $draft['import_amount'] ?? 0);
                if (! hash_equals($checkout->fingerprint($quote), $data['quote_hash'])
                    || ! hash_equals($draft['quote_hash'], $data['quote_hash'])) {
                    throw ValidationException::withMessages(['quote_hash' => $draft['locale'] === 'es'
                        ? 'El catálogo ha cambiado. Comprueba el resumen actualizado y confirma de nuevo.'
                        : 'Il catalogo è cambiato. Verifica il riepilogo aggiornato e conferma nuovamente.']);
                }
                $name = trim($data['first_name'].' '.$data['last_name']);
                $address = [
                    'name' => $name,
                    'line1' => $data['line1'],
                    'line2' => $data['line2'] ?? null,
                    'postal_code' => $data['postal_code'],
                    'city' => $data['city'],
                    'province' => $data['province'],
                    'country' => $country,
                ];
                $order = ItalianOrder::create([
                    'checkout_token' => $token,
                    'is_test' => ! StripePayments::live(),
                    'checkout_locale' => $draft['locale'],
                    'checkout_origin' => $draft['origin'],
                    'customer_name' => $name,
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'shipping_address' => $address,
                    'billing_address' => $address,
                    'currency' => 'EUR',
                    'subtotal_amount' => $quote['subtotal_amount'],
                    'import_amount' => $quote['import_amount'],
                    'discount_amount' => $quote['discount_amount'],
                    'shipping_amount' => 0,
                    'total_amount' => $quote['total_amount'],
                    'payment_status' => 'pending',
                    'fulfillment_status' => 'pending',
                ]);
                $order->items()->createMany($quote['items']);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            // The unique token also prevents duplicate orders across concurrent requests.
            if (! ItalianOrder::where('checkout_token', $token)->exists()) {
                throw $exception;
            }
        }

        return $this->redirectTo('italian-checkout.payment', $token);
    }

    public function confirmation(Request $request, string $token, StripePayments $payments): Response
    {
        $draft = $this->draft($request, $token);
        $order = ItalianOrder::where('checkout_token', $token)->firstOrFail();

        $syncError = false;
        $latest = $order->payments()->latest('id')->first();
        if (StripePayments::configured() && $latest?->stripe_session_id) {
            try {
                $payments->synchronize($latest);
                $order->refresh();
            } catch (ApiErrorException|\LogicException|\UnexpectedValueException $exception) {
                $syncError = true;
            }
        }

        return Inertia::render('ItalianCheckout/Confirmation', [
            'paymentUrl' => route('italian-checkout.payment', $token, false),
            'syncError' => $syncError,
            'isTest' => $order->is_test,
            'checkoutLocale' => $order->checkout_locale ?: $draft['locale'],
            'order' => $order->only(['number', 'customer_name', 'total_amount', 'currency', 'payment_status', 'is_test', 'fulfillment_status']),
        ]);
    }

    public function payment(Request $request, string $token): Response|RedirectResponse
    {
        $draft = $this->draft($request, $token);
        $order = ItalianOrder::where('checkout_token', $token)->firstOrFail();
        if ($order->fulfillment_status === 'cancelled' || in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
            return $this->redirectTo('italian-checkout.confirmation', $token);
        }

        return Inertia::render('ItalianCheckout/Payment', [
            'token' => $token,
            'isTest' => $order->is_test,
            'checkoutLocale' => $order->checkout_locale ?: $draft['locale'],
            'publishableKey' => StripePayments::supportsOrder($order) ? config('stripe.key') : null,
            'order' => [
                ...$order->only(['number', 'total_amount', 'subtotal_amount', 'import_amount', 'discount_amount', 'shipping_amount', 'currency']),
                'items' => $order->items()->get(['title', 'variant_title', 'quantity', 'unit_amount', 'import_unit_amount', 'total_amount', 'import_total_amount']),
            ],
            'confirmationUrl' => route('italian-checkout.confirmation', $token, false),
        ]);
    }

    public function stripeSession(Request $request, string $token, StripePayments $payments): JsonResponse
    {
        $draft = $this->draft($request, $token);
        $order = ItalianOrder::where('checkout_token', $token)->firstOrFail();
        if ($order->fulfillment_status === 'cancelled') {
            return response()->json(['redirect' => route('italian-checkout.confirmation', $token, false)]);
        }
        if (! StripePayments::configured()) {
            return response()->json(['error' => 'Il pagamento non è ancora disponibile.'], 503);
        }
        try {
            $returnPath = route('italian-checkout.confirmation', $token, false);
            $session = $payments->session($order, StripePayments::live()
                ? rtrim($order->checkout_origin ?: $draft['origin'], '/').$returnPath
                : $request->getSchemeAndHttpHost().$returnPath);
        } catch (ApiErrorException|\LogicException|\UnexpectedValueException $exception) {
            return response()->json(['error' => 'Impossibile avviare il pagamento. Il tuo ordine è salvato: riprova tra poco.'], 503);
        }
        if (! $session || $session['status'] === 'complete') {
            return response()->json(['redirect' => route('italian-checkout.confirmation', $token, false)])->header('Cache-Control', 'no-store');
        }
        if (empty($session['client_secret']) || $session['status'] !== 'open') {
            return response()->json(['error' => 'Sessione scaduta. Riprova per aprirne una nuova.'], 409);
        }

        return response()->json(['clientSecret' => $session['client_secret']])->header('Cache-Control', 'no-store');
    }

    private function paymentDestination(ItalianOrder $order, string $token): RedirectResponse
    {
        return $this->redirectTo(in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)
            ? 'italian-checkout.confirmation' : 'italian-checkout.payment', $token);
    }

    private function draft(Request $request, string $token): array
    {
        abort_unless(ItalianCheckout::availableForRequest($request), 404);
        abort_if(ItalianOrder::onlyTrashed()->where('checkout_token', $token)->exists(), 410, 'Ordine di prova eliminato. Avvia un nuovo checkout.');
        $draft = $request->session()->get("italian_checkout_drafts.$token");
        abort_unless(is_array($draft), 404);

        return [
            ...$draft,
            'discount' => $draft['discount'] ?? null,
            'locale' => in_array($draft['locale'] ?? null, ['it', 'es'], true) ? $draft['locale'] : 'it',
            'origin' => $draft['origin'] ?? $request->getSchemeAndHttpHost(),
            'locked_quote' => is_array($draft['locked_quote'] ?? null) ? $draft['locked_quote'] : null,
        ];
    }

    private function redirectTo(string $route, string $token): RedirectResponse
    {
        return redirect()->to(route($route, $token, false));
    }
}
