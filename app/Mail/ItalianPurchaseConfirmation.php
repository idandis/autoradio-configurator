<?php

namespace App\Mail;

use App\Models\ItalianOrder;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ItalianPurchaseConfirmation extends Mailable
{
    public function __construct(public ItalianOrder $order, public bool $preview = false) {}

    public function envelope(): Envelope
    {
        $spanish = $this->order->checkout_locale === 'es';

        return new Envelope(subject: ($this->preview ? ($spanish ? '[PRUEBA] ' : '[PROVA] ') : '')
            .($spanish ? 'Confirmación de compra ' : 'Conferma acquisto ')
            .$this->order->number.' · '.($spanish ? 'Autoradio Canario' : 'Autoradio Italiano'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.italian-orders.purchase');
    }
}
