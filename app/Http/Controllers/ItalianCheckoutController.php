<?php

namespace App\Http\Controllers;

use App\Models\ItalianOrder;
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
    public function start(Request $request, ItalianCheckout $checkout): RedirectResponse
    {
        abort_unless(ItalianCheckout::availableForRequest($request), 404);
        $items = $checkout->normalizeItems($request->all());
        $quote = $checkout->quote($items);
        $drafts = $request->session()->get('italian_checkout_drafts', []);
        // Repeated clicks for the same active cart reuse the same idempotency token.
        foreach ($drafts as $token => $draft) {
            if ($draft['items'] === $items && ! ItalianOrder::withTrashed()->where('checkout_token', $token)->exists()) {
                return to_route('italian-checkout.show', $token);
            }
        }
        $token = (string) Str::uuid();
        $drafts[$token] = ['items' => $items, 'quote_hash' => $checkout->fingerprint($quote)];
        $request->session()->put('italian_checkout_drafts', array_slice($drafts, -20, null, true));

        return to_route('italian-checkout.show', $token);
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
        try {
            $quote = $checkout->quote($draft['items']);
            $hash = $checkout->fingerprint($quote);
            $changed = $hash !== $draft['quote_hash'];
            $request->session()->put("italian_checkout_drafts.$token.quote_hash", $hash);
        } catch (ValidationException $exception) {
            $unavailable = $exception->validator->errors()->first();
        }

        return Inertia::render('ItalianCheckout/Checkout', [
            'token' => $token,
            'quote' => $quote,
            'quoteHash' => $quote ? $checkout->fingerprint($quote) : null,
            'changed' => $changed,
            'unavailable' => $unavailable,
            'isTest' => ! StripePayments::live(),
        ]);
    }

    public function store(Request $request, string $token, ItalianCheckout $checkout): RedirectResponse
    {
        $draft = $this->draft($request, $token);
        if ($existing = ItalianOrder::where('checkout_token', $token)->first()) {
            return $this->paymentDestination($existing, $token);
        }
        if (is_string($request->input('province'))) {
            $request->merge(['province' => mb_strtoupper(trim($request->input('province')))]);
        }
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[+\d\s().\/-]{5,40}$/'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'country' => ['required', Rule::in(['IT'])],
            'reviewed' => ['accepted'],
            'quote_hash' => ['required', 'string', 'size:64'],
        ], [
            'required' => 'Compila questo campo.',
            'email.email' => 'Inserisci un indirizzo email valido.',
            'phone.regex' => 'Inserisci un numero di telefono valido.',
            'postal_code.regex' => 'Il CAP deve contenere 5 cifre.',
            'province.regex' => 'Indica la sigla della provincia, ad esempio RM.',
            'country.in' => 'La spedizione è disponibile solo in Italia.',
            'reviewed.accepted' => 'Conferma di aver verificato i dati dell’ordine.',
        ]);

        try {
            DB::transaction(function () use ($data, $draft, $token, $checkout) {
                if (ItalianOrder::where('checkout_token', $token)->exists()) {
                    return;
                }
                $quote = $checkout->quote($draft['items'], lock: true);
                if (! hash_equals($checkout->fingerprint($quote), $data['quote_hash'])
                    || ! hash_equals($draft['quote_hash'], $data['quote_hash'])) {
                    throw ValidationException::withMessages(['quote_hash' => 'Il catalogo è cambiato. Verifica il riepilogo aggiornato e conferma nuovamente.']);
                }
                $name = trim($data['first_name'].' '.$data['last_name']);
                $address = [
                    'name' => $name,
                    'line1' => $data['line1'],
                    'line2' => $data['line2'] ?? null,
                    'postal_code' => $data['postal_code'],
                    'city' => $data['city'],
                    'province' => $data['province'],
                    'country' => 'IT',
                ];
                $order = ItalianOrder::create([
                    'checkout_token' => $token,
                    'is_test' => ! StripePayments::live(),
                    'customer_name' => $name,
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'shipping_address' => $address,
                    'billing_address' => $address,
                    'currency' => 'EUR',
                    'subtotal_amount' => $quote['subtotal_amount'],
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

        return to_route('italian-checkout.payment', $token);
    }

    public function confirmation(Request $request, string $token, StripePayments $payments): Response
    {
        $this->draft($request, $token);
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
            'paymentUrl' => route('italian-checkout.payment', $token),
            'syncError' => $syncError,
            'isTest' => $order->is_test,
            'order' => $order->only(['number', 'customer_name', 'total_amount', 'currency', 'payment_status', 'is_test', 'fulfillment_status']),
        ]);
    }

    public function payment(Request $request, string $token): Response|RedirectResponse
    {
        $this->draft($request, $token);
        $order = ItalianOrder::where('checkout_token', $token)->firstOrFail();
        if ($order->fulfillment_status === 'cancelled' || in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
            return to_route('italian-checkout.confirmation', $token);
        }

        return Inertia::render('ItalianCheckout/Payment', [
            'token' => $token,
            'isTest' => $order->is_test,
            'publishableKey' => StripePayments::supportsOrder($order) ? config('stripe.key') : null,
            'order' => [
                ...$order->only(['number', 'total_amount', 'subtotal_amount', 'discount_amount', 'shipping_amount', 'currency']),
                'items' => $order->items()->get(['title', 'variant_title', 'quantity', 'total_amount']),
            ],
            'confirmationUrl' => route('italian-checkout.confirmation', $token),
        ]);
    }

    public function stripeSession(Request $request, string $token, StripePayments $payments): JsonResponse
    {
        $this->draft($request, $token);
        $order = ItalianOrder::where('checkout_token', $token)->firstOrFail();
        if ($order->fulfillment_status === 'cancelled') {
            return response()->json(['redirect' => route('italian-checkout.confirmation', $token)]);
        }
        if (! StripePayments::configured()) {
            return response()->json(['error' => 'Il pagamento non è ancora disponibile.'], 503);
        }
        try {
            $session = $payments->session($order, StripePayments::live()
                ? rtrim(config('italian_checkout.origin'), '/').route('italian-checkout.confirmation', $token, false)
                : route('italian-checkout.confirmation', $token));
        } catch (ApiErrorException|\LogicException|\UnexpectedValueException $exception) {
            return response()->json(['error' => 'Impossibile avviare il pagamento. Il tuo ordine è salvato: riprova tra poco.'], 503);
        }
        if (! $session || $session['status'] === 'complete') {
            return response()->json(['redirect' => route('italian-checkout.confirmation', $token)])->header('Cache-Control', 'no-store');
        }
        if (empty($session['client_secret']) || $session['status'] !== 'open') {
            return response()->json(['error' => 'Sessione scaduta. Riprova per aprirne una nuova.'], 409);
        }

        return response()->json(['clientSecret' => $session['client_secret']])->header('Cache-Control', 'no-store');
    }

    private function paymentDestination(ItalianOrder $order, string $token): RedirectResponse
    {
        return to_route(in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)
            ? 'italian-checkout.confirmation' : 'italian-checkout.payment', $token);
    }

    private function draft(Request $request, string $token): array
    {
        abort_unless(ItalianCheckout::availableForRequest($request), 404);
        abort_if(ItalianOrder::onlyTrashed()->where('checkout_token', $token)->exists(), 410, 'Ordine di prova eliminato. Avvia un nuovo checkout.');
        $draft = $request->session()->get("italian_checkout_drafts.$token");
        abort_unless(is_array($draft), 404);

        return $draft;
    }
}
