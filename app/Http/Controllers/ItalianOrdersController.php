<?php

namespace App\Http\Controllers;

use App\Models\ItalianOrder;
use App\Services\ItalianOrderRefunds;
use App\Services\StripeGateway;
use App\Services\StripePayments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;

class ItalianOrdersController extends Controller
{
    public function destroy(ItalianOrder $italianOrder): RedirectResponse
    {
        abort_unless($italianOrder->is_test, 403);
        $italianOrder->delete();

        return to_route('italian-orders.index')->with('status', 'Ordine di prova eliminato.');
    }

    public function destroyTests(): RedirectResponse
    {
        ItalianOrder::where('is_test', true)->delete();

        return to_route('italian-orders.index')->with('status', 'Ordini di prova eliminati.');
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'payment_status' => ['nullable', Rule::in(array_keys(ItalianOrder::PAYMENT_STATUSES))],
            'fulfillment_status' => ['nullable', Rule::in(array_keys(ItalianOrder::FULFILLMENT_STATUSES))],
        ]);

        $orders = ItalianOrder::query()
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('number', 'like', '%'.$search.'%')
                        ->orWhere('customer_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['payment_status'] ?? null, fn ($query, $status) => $query->where('payment_status', $status))
            ->when($filters['fulfillment_status'] ?? null, fn ($query, $status) => $query->where('fulfillment_status', $status))
            ->latest('id')
            ->paginate(25, ['id', 'number', 'customer_name', 'email', 'total_amount', 'currency', 'payment_status', 'fulfillment_status', 'created_at', 'is_test'])
            ->withQueryString();

        return Inertia::render('ItalianOrders/Index', [
            'orders' => $orders,
            'testOrderCount' => ItalianOrder::where('is_test', true)->count(),
            'flashStatus' => session('status'),
            'filters' => $filters,
            'paymentStatuses' => ItalianOrder::PAYMENT_STATUSES,
            'fulfillmentStatuses' => ItalianOrder::FULFILLMENT_STATUSES,
        ]);
    }

    public function show(ItalianOrder $italianOrder): Response
    {
        return Inertia::render('ItalianOrders/Show', [
            'order' => $italianOrder->load(['refunds', 'items', 'events' => fn ($query) => $query->latest('id')->with('user:id,name')]),
            'paymentStatuses' => ItalianOrder::PAYMENT_STATUSES,
            'fulfillmentStatuses' => ItalianOrder::FULFILLMENT_STATUSES,
            'purchaseEmail' => DB::table('italian_order_emails')->where('italian_order_id', $italianOrder->id)->where('kind', 'purchase')->first(['status', 'prepared_at']),
            'refundsEnabled' => StripePayments::supportsOrder($italianOrder),
            'allowedFulfillmentStatuses' => $italianOrder->allowedFulfillmentStatuses(),
            'flashStatus' => session('status'),
        ]);
    }

    public function purchaseEmail(ItalianOrder $italianOrder): \Illuminate\Http\Response
    {
        $email = DB::table('italian_order_emails')->where('italian_order_id', $italianOrder->id)->where('kind', 'purchase')->first();
        abort_unless($email, 404);

        return response($email->html)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'private, no-store')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }

    public function refund(Request $request, ItalianOrder $italianOrder, ItalianOrderRefunds $refunds): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/^\d{1,8}([.,]\d{1,2})?$/'],
        ]);
        $parts = explode('.', str_replace(',', '.', $data['amount']));
        $amount = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        try {
            $refunds->refund($italianOrder, $amount, (int) $data['version'], $request->user()->id);
        } catch (ApiErrorException|\LogicException $exception) {
            throw ValidationException::withMessages(['refund' => 'Stripe non ha confermato il rimborso. Aggiorna lo stato prima di riprovare; una richiesta già inviata verrà riutilizzata.']);
        }

        return to_route('italian-orders.show', $italianOrder)->with('status', 'Richiesta elaborata. Controlla lo stato nella sezione Rimborsi.');
    }

    public function syncRefunds(ItalianOrder $italianOrder, ItalianOrderRefunds $refunds): RedirectResponse
    {
        try {
            $refunds->synchronize($italianOrder);
        } catch (ApiErrorException|\LogicException $exception) {
            throw ValidationException::withMessages(['refund' => 'Impossibile verificare i rimborsi su Stripe. Riprova tra poco.']);
        }

        return to_route('italian-orders.show', $italianOrder)->with('status', 'Stato rimborsi aggiornato da Stripe.');
    }

    public function cancel(Request $request, ItalianOrder $italianOrder, StripeGateway $gateway): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $italianOrder, $gateway, $data) {
            $order = ItalianOrder::query()->lockForUpdate()->findOrFail($italianOrder->id);
            if ($order->fulfillment_status === 'cancelled') {
                return;
            }
            if ($order->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Ordine aggiornato. Ricarica la pagina prima di annullare.']);
            }
            if (! in_array($order->fulfillment_status, ['pending', 'processing'], true)) {
                throw ValidationException::withMessages(['cancel' => 'Un ordine già spedito o consegnato non può essere annullato.']);
            }
            if ($order->payments()->exists() && ! StripePayments::supportsOrder($order)) {
                throw ValidationException::withMessages(['cancel' => 'La modalità Stripe non corrisponde a quella dell’ordine.']);
            }
            foreach ($order->payments()->whereIn('status', ['creating', 'open'])->get() as $payment) {
                if (! $payment->stripe_session_id) {
                    throw ValidationException::withMessages(['cancel' => 'Un pagamento è in fase di apertura. Apri la pagina di pagamento e riprova ad annullare.']);
                }
                try {
                    $session = $gateway->retrieveSession($payment->stripe_session_id);
                    if ($session['status'] === 'open') {
                        $session = $gateway->expireSession($payment->stripe_session_id);
                    }
                    $payment->update(['status' => $session['status']]);
                } catch (ApiErrorException|\LogicException $exception) {
                    throw ValidationException::withMessages(['cancel' => 'Non è stato possibile chiudere il pagamento su Stripe. Ordine non annullato: riprova.']);
                }
            }
            $from = $order->fulfillment_status;
            $order->fulfillment_status = 'cancelled';
            $order->version++;
            $order->save();
            $order->events()->create([
                'user_id' => $request->user()->id,
                'from_status' => $from,
                'to_status' => 'cancelled',
                'changes' => ['fulfillment_status' => 'cancelled'],
            ]);
        });

        return to_route('italian-orders.show', $italianOrder)->with('status', 'Ordine annullato. Nessun rimborso eseguito.');
    }

    public function update(Request $request, ItalianOrder $italianOrder): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'fulfillment_status' => ['required', Rule::in(array_keys(ItalianOrder::FULFILLMENT_STATUSES))],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
            'payment_status' => ['prohibited'],
            'total_amount' => ['prohibited'],
        ]);

        DB::transaction(function () use ($request, $italianOrder, $data) {
            $order = ItalianOrder::query()->lockForUpdate()->findOrFail($italianOrder->id);
            if ($order->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Ordine aggiornato da un altro operatore. Ricarica la pagina prima di salvare.']);
            }
            if ($order->fulfillment_status === 'cancelled') {
                throw ValidationException::withMessages(['fulfillment_status' => 'Ordine annullato: gestione bloccata.']);
            }
            if (! in_array($data['fulfillment_status'], $order->allowedFulfillmentStatuses(), true)) {
                throw ValidationException::withMessages(['fulfillment_status' => 'Passaggio non consentito. Verifica il pagamento e lo stato attuale della spedizione.']);
            }
            if (in_array($data['fulfillment_status'], ['shipped', 'delivered'], true)
                && (blank($data['carrier'] ?? null) || blank($data['tracking_number'] ?? null))) {
                throw ValidationException::withMessages(['tracking_number' => 'Indica corriere e codice di tracciamento per un ordine spedito.']);
            }

            $from = $order->fulfillment_status;
            $order->fill(collect($data)->only(['fulfillment_status', 'carrier', 'tracking_number', 'internal_notes'])->all());
            $changes = $order->getDirty();
            if ($changes === []) {
                return;
            }
            if ($from !== $order->fulfillment_status) {
                if ($order->fulfillment_status === 'shipped') {
                    $order->shipped_at = now();
                } elseif ($order->fulfillment_status === 'delivered') {
                    $order->delivered_at = now();
                }
            }
            $order->version++;
            $order->save();
            $order->events()->create([
                'user_id' => $request->user()->id,
                'from_status' => $from,
                'to_status' => $order->fulfillment_status,
                'changes' => $changes,
            ]);
        });

        return to_route('italian-orders.show', $italianOrder)->with('status', 'Ordine aggiornato.');
    }
}
