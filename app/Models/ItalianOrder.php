<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ItalianOrder extends Model
{
    use SoftDeletes;

    public const PAYMENT_STATUSES = [
        'pending' => 'In attesa di pagamento',
        'paid' => 'Pagato',
        'failed' => 'Pagamento non riuscito',
        'partially_refunded' => 'Rimborsato parzialmente',
        'refunded' => 'Rimborsato',
    ];

    public const FULFILLMENT_STATUSES = [
        'pending' => 'Da preparare',
        'processing' => 'In preparazione',
        'shipped' => 'Spedito',
        'delivered' => 'Consegnato',
        'cancelled' => 'Annullato',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'is_test' => 'boolean',
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'subtotal_amount' => 'integer',
        'import_amount' => 'integer',
        'shipping_amount' => 'integer',
        'discount_amount' => 'integer',
        'total_amount' => 'integer',
        'version' => 'integer',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->number ??= ($order->checkout_locale === 'es' ? 'ES-' : 'IT-').Str::ulid();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItalianOrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ItalianOrderEvent::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(ItalianOrderRefund::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ItalianOrderPayment::class);
    }

    /** @return array<int, string> */
    public function allowedFulfillmentStatuses(): array
    {
        $next = match ($this->fulfillment_status) {
            'pending' => ['processing', 'shipped'],
            'processing' => ['shipped'],
            'shipped' => ['delivered'],
            default => [],
        };

        // Recording delivery remains possible after a refund for an order already shipped.
        if ($this->fulfillment_status !== 'shipped'
            && ! in_array($this->payment_status, ['paid', 'partially_refunded'], true)) {
            $next = [];
        }

        return [$this->fulfillment_status, ...$next];
    }
}
