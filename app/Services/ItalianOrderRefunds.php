<?php

namespace App\Services;

use App\Models\ItalianOrder;
use App\Models\ItalianOrderRefund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Stripe\Exception\InvalidRequestException;

class ItalianOrderRefunds
{
    public function __construct(private StripeGateway $gateway) {}

    private function intent(ItalianOrder $order): string
    {
        if (! StripePayments::supportsOrder($order) || $order->currency !== 'EUR') {
            throw new LogicException('Refund configuration does not match the order.');
        }
        $intent = $order->payments()->where('status', 'complete')->whereNotNull('stripe_payment_intent_id')->latest('id')->value('stripe_payment_intent_id');
        if (! $intent) {
            throw new LogicException('Missing confirmed payment.');
        }

        return $intent;
    }

    public function synchronize(ItalianOrder $order): void
    {
        $intent = $this->intent($order);
        $payment = $this->gateway->retrievePaymentIntent($intent);
        if (($payment['livemode'] ?? null) !== StripePayments::live() || ($payment['currency'] ?? null) !== 'eur'
            || ($payment['amount_received'] ?? null) !== $order->total_amount || ($payment['status'] ?? null) !== 'succeeded'
            || ($payment['metadata']['italian_order_id'] ?? null) !== (string) $order->id) {
            throw new LogicException('Payment mismatch.');
        }
        // Fetch under the order lock to serialize refund snapshots and reservations.
        DB::transaction(function () use ($order, $intent) {
            $locked = ItalianOrder::query()->lockForUpdate()->findOrFail($order->id);
            foreach ($this->gateway->listRefunds($intent) as $refund) {
                $this->apply($locked, $intent, $refund);
            }
        });
    }

    public function refund(ItalianOrder $order, int $amount, int $version, int $userId): void
    {
        $this->synchronize($order);
        $intent = $this->intent($order);
        $attempt = DB::transaction(function () use ($order, $amount, $version, $userId, $intent) {
            $locked = ItalianOrder::query()->lockForUpdate()->findOrFail($order->id);
            $active = $locked->refunds()->where('status', 'creating')->first();
            if ($active) {
                if ($active->amount !== $amount || $active->created_at->lt(now()->subHours(23))) {
                    throw ValidationException::withMessages(['refund' => 'Verifica il rimborso già richiesto prima di inviarne un altro.']);
                }

                return $active;
            }
            if ($locked->version !== $version) {
                throw ValidationException::withMessages(['refund' => 'Ordine aggiornato: aggiorna lo stato prima di rimborsare.']);
            }
            $reserved = (int) $locked->refunds()->whereNotIn('status', ['failed', 'canceled'])->sum('amount');
            if (! in_array($locked->payment_status, ['paid', 'partially_refunded'], true) || $amount < 1 || $amount > $locked->total_amount - $reserved) {
                throw ValidationException::withMessages(['amount' => 'Importo superiore al residuo rimborsabile o pagamento non confermato.']);
            }

            return $locked->refunds()->create([
                'user_id' => $userId, 'request_key' => (string) Str::uuid(),
                'payment_intent' => $intent, 'amount' => $amount,
            ]);
        });
        try {
            $refund = $this->gateway->createRefund([
                'payment_intent' => $attempt->payment_intent, 'amount' => $attempt->amount,
                'metadata' => ['italian_order_id' => (string) $order->id, 'italian_refund_id' => (string) $attempt->id],
            ], $attempt->request_key);
        } catch (InvalidRequestException $exception) {
            // Stripe rejected the parameters before performing a refund.
            $attempt->update(['status' => 'failed']);
            throw $exception;
        }
        DB::transaction(function () use ($order, $intent, $refund) {
            $locked = ItalianOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->apply($locked, $intent, $refund);
        });
    }

    private function apply(ItalianOrder $order, string $intent, array $refund): void
    {
        if (($refund['payment_intent'] ?? null) !== $intent || ($refund['currency'] ?? null) !== 'eur'
            || ! is_int($refund['amount'] ?? null) || $refund['amount'] < 1 || $refund['amount'] > $order->total_amount
            || ! str_starts_with($refund['id'] ?? '', 're_')
            || ! in_array($refund['status'] ?? null, ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'], true)) {
            throw new LogicException('Refund mismatch.');
        }
        $record = $order->refunds()->where('stripe_refund_id', $refund['id'])->first();
        $record ??= $order->refunds()->whereKey($refund['metadata']['italian_refund_id'] ?? 0)->first();
        if ($record && ($record->amount !== $refund['amount'] || $record->payment_intent !== $intent
            || ($record->stripe_refund_id && $record->stripe_refund_id !== $refund['id']))) {
            throw new LogicException('Refund request mismatch.');
        }
        if ($record && in_array($record->status, ['succeeded', 'failed', 'canceled'], true)) {
            return;
        }
        $changed = ! $record || $record->status !== $refund['status'];
        $record ??= new ItalianOrderRefund(['italian_order_id' => $order->id, 'request_key' => (string) Str::uuid(), 'payment_intent' => $intent, 'amount' => $refund['amount']]);
        $record->fill(['stripe_refund_id' => $refund['id'], 'status' => $refund['status']])->save();
        if (! $changed) {
            return;
        }
        $sum = (int) $order->refunds()->where('status', 'succeeded')->sum('amount');
        $from = $order->payment_status;
        $order->payment_status = $sum >= $order->total_amount ? 'refunded' : ($sum > 0 ? 'partially_refunded' : 'paid');
        $order->version++;
        $order->save();
        $order->events()->create([
            'kind' => 'payment', 'user_id' => $record->user_id, 'from_status' => $from, 'to_status' => $order->payment_status,
            'changes' => ['refund' => number_format($record->amount / 100, 2, ',', '.').' EUR · '.$record->status, 'stripe_refund_id' => $refund['id']],
        ]);
    }
}
