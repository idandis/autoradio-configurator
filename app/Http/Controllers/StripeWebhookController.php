<?php

namespace App\Http\Controllers;

use App\Models\ItalianOrderPayment;
use App\Services\ItalianOrderRefunds;
use App\Services\StripePayments;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripePayments $payments)
    {
        abort_unless(StripePayments::configured(), 404);
        $secret = (string) config('stripe.webhook_secret');
        if (! str_starts_with($secret, 'whsec_')) {
            return response()->json(['error' => 'Webhook not configured.'], 503);
        }
        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException $exception) {
            return response()->json(['error' => 'Invalid signature.'], 400);
        }
        if ($event->livemode !== StripePayments::live()) {
            return response()->json(['error' => 'Incorrect payment mode.'], 400);
        }
        $object = $event->data->object;
        if (in_array($event->type, ['refund.created', 'refund.updated', 'refund.failed', 'charge.refunded'], true)) {
            $intent = $object->payment_intent ?? null;
            $refundPayment = $intent ? ItalianOrderPayment::where('stripe_payment_intent_id', $intent)->first() : null;
            if ($refundPayment?->order) {
                try {
                    app(ItalianOrderRefunds::class)->synchronize($refundPayment->order);
                } catch (ApiErrorException|\LogicException $exception) {
                    return response()->json(['error' => 'Refund verification unavailable.'], 503);
                }
            }

            return response()->json(['received' => true]);
        }
        $payment = null;
        $sessionId = null;
        if (in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'], true)) {
            $sessionId = $object->id;
            $payment = ItalianOrderPayment::where('stripe_session_id', $sessionId)->first();
            $payment ??= ItalianOrderPayment::find($object->metadata->italian_payment_id ?? null);
        } elseif (in_array($event->type, ['payment_intent.payment_failed', 'payment_intent.succeeded'], true)) {
            $payment = ItalianOrderPayment::find($object->metadata->italian_payment_id ?? null);
        }
        if (! $payment || ! $payment->order) {
            return response()->json(['received' => true]);
        }
        if (! $payment->stripe_session_id && ! $sessionId) {
            // Retry after the create-session response has been persisted.
            return response()->json(['error' => 'Session pending.'], 503);
        }
        try {
            // Retrieve current state from Stripe, so late failure events cannot undo success.
            $payments->synchronize($payment, $sessionId);
        } catch (ApiErrorException $exception) {
            return response()->json(['error' => 'Retry later.'], 503);
        } catch (\LogicException|UnexpectedValueException $exception) {
            return response()->json(['error' => 'Session mismatch.'], 400);
        }

        return response()->json(['received' => true]);
    }
}
