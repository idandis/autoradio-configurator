<!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Conferma acquisto</title></head>
<body style="margin:0;background:#f3f4f6;color:#171717;font-family:Arial,sans-serif;line-height:1.6">
@php($money = fn ($amount) => number_format($amount / 100, 2, ',', '.').' €')
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff"><tr><td style="padding:28px">
<p style="margin:0;font-weight:bold;font-size:20px">AUTORADIO ITALIANO</p>
@if($order->is_test)<p style="padding:12px;background:#fff3cd">PROVA LOCALE — pagamento simulato, nessun addebito reale.</p>@endif
<h1 style="font-size:25px">Grazie per il tuo acquisto!</h1>
<p>Ciao {{ $order->customer_name }},<br>abbiamo ricevuto il pagamento del tuo ordine <strong>{{ $order->number }}</strong>.</p>
@if($order->fulfillment_status === 'cancelled')
<p>L’ordine risulta annullato. Questa email conferma soltanto la ricezione del pagamento, non la spedizione né un rimborso.</p>
@else
<p>Verificheremo la compatibilità dei prodotti prima della spedizione.</p>
@endif
<h2 style="font-size:18px">Riepilogo acquisto</h2>
<table width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;text-align:left">
<thead><tr><th scope="col">Prodotto</th><th scope="col">Qtà</th><th scope="col" style="text-align:right">Importo</th></tr></thead>
<tbody>@foreach($order->items as $item)<tr style="border-top:1px solid #e5e7eb"><td>{{ $item->title }}@if($item->variant_title)<br><small>{{ $item->variant_title }}</small>@endif</td><td>{{ $item->quantity }}</td><td style="text-align:right;white-space:nowrap">{{ $money($item->total_amount) }}</td></tr>@endforeach</tbody>
</table>
<p>Prodotti: {{ $money($order->subtotal_amount) }}<br>
@if($order->discount_amount)Sconto: −{{ $money($order->discount_amount) }}<br>@endif
Spedizione: {{ $order->shipping_amount === 0 ? 'Gratuita' : $money($order->shipping_amount) }}<br>
<strong>Totale pagato: {{ $money($order->total_amount) }}</strong></p>
<h2 style="font-size:18px">Indirizzo di spedizione</h2>
@php($address = $order->shipping_address)
<p>{{ $address['name'] ?? $order->customer_name }}<br>{{ $address['line1'] ?? '' }}<br>
@if(!empty($address['line2'])){{ $address['line2'] }}<br>@endif
{{ $address['postal_code'] ?? '' }} {{ $address['city'] ?? '' }} ({{ $address['province'] ?? '' }})<br>{{ $address['country'] ?? 'IT' }}</p>
<p style="font-size:12px;color:#666">Conferma d’acquisto · {{ $order->number }}<br>Questo messaggio non è una fattura.</p>
</td></tr></table></td></tr></table></body></html>
