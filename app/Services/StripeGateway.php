<?php

namespace App\Services;

use LogicException;
use Stripe\StripeClient;

class StripeGateway
{
    public function createSession(array $payload, string $idempotencyKey): array
    {
        return $this->client()->checkout->sessions->create($payload, ['idempotency_key' => $idempotencyKey])->toArray();
    }

    public function retrieveSession(string $id): array
    {
        return $this->client()->checkout->sessions->retrieve($id, ['expand' => ['payment_intent']])->toArray();
    }

    public function expireSession(string $id): array
    {
        return $this->client()->checkout->sessions->expire($id)->toArray();
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->client()->paymentIntents->retrieve($id)->toArray();
    }

    public function createRefund(array $payload, string $key): array
    {
        return $this->client()->refunds->create($payload, ['idempotency_key' => $key])->toArray();
    }

    public function listRefunds(string $intent): array
    {
        $refunds = [];
        foreach ($this->client()->refunds->all(['payment_intent' => $intent, 'limit' => 100])->autoPagingIterator() as $refund) {
            $refunds[] = $refund->toArray();
        }

        return $refunds;
    }

    private function client(): StripeClient
    {
        if (! StripePayments::configured()) {
            throw new LogicException('Stripe is not configured.');
        }

        return new StripeClient(['api_key' => config('stripe.secret'), 'max_network_retries' => 1]);
    }
}
