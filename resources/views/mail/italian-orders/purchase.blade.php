<!doctype html>
<html lang="{{ $order->checkout_locale === 'es' ? 'es' : 'it' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ $order->checkout_locale === 'es' ? 'Confirmación de compra' : 'Conferma acquisto' }}</title>
</head>
<body style="margin:0;padding:0;background:#121212;color:#ffffff;font-family:Arial,Helvetica,sans-serif;line-height:1.6">
@php
    $money = fn ($amount) => number_format($amount / 100, 2, ',', '.').' €';
    $address = $order->shipping_address;
    $es = $order->checkout_locale === 'es';
    $copy = $es ? [
        'brand' => 'CANARIO', 'preview' => 'EMAIL DE PRUEBA · ningún pago nuevo', 'test' => 'PRUEBA LOCAL · pago simulado, ningún cargo real',
        'thanks' => '¡Gracias por tu compra!', 'hello' => 'Hola', 'received' => 'hemos recibido el pago de tu pedido',
        'cancelled' => 'El pedido está cancelado. Este email confirma únicamente la recepción del pago, no el envío ni un reembolso.',
        'compatibility' => 'Comprobaremos la compatibilidad de los productos antes del envío.', 'summary' => 'Resumen de compra',
        'product' => 'Producto', 'quantity' => 'Cant.', 'amount' => 'Importe', 'products' => 'Productos', 'imports' => 'Costes de importación',
        'discount' => 'Descuento', 'shipping' => 'Entrega', 'free' => 'Incluida', 'total' => 'Total pagado', 'address' => 'Dirección de entrega',
        'confirmation' => 'Confirmación de compra', 'not_invoice' => 'Este mensaje no es una factura.',
    ] : [
        'brand' => 'ITALIANO', 'preview' => 'EMAIL DI PROVA · nessun nuovo pagamento', 'test' => 'PROVA LOCALE · pagamento simulato, nessun addebito reale',
        'thanks' => 'Grazie per il tuo acquisto!', 'hello' => 'Ciao', 'received' => 'abbiamo ricevuto il pagamento del tuo ordine',
        'cancelled' => 'L’ordine risulta annullato. Questa email conferma soltanto la ricezione del pagamento, non la spedizione né un rimborso.',
        'compatibility' => 'Verificheremo la compatibilità dei prodotti prima della spedizione.', 'summary' => 'Riepilogo acquisto',
        'product' => 'Prodotto', 'quantity' => 'Qtà', 'amount' => 'Importo', 'products' => 'Prodotti', 'imports' => 'Costi di importazione',
        'discount' => 'Sconto', 'shipping' => 'Spedizione', 'free' => 'Inclusa', 'total' => 'Totale pagato', 'address' => 'Indirizzo di spedizione',
        'confirmation' => 'Conferma d’acquisto', 'not_invoice' => 'Questo messaggio non è una fattura.',
    ];
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#121212">
    <tr>
        <td align="center" style="padding:32px 12px">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:620px;background:#181818;border:1px solid #303030;border-radius:16px">
                <tr>
                    <td align="center" style="padding:32px 28px 24px;border-bottom:1px solid #303030">
                        <img src="{{ url($es ? '/images/logo.png' : '/images/logo-it.png') }}" width="112" alt="Autoradio {{ $copy['brand'] }}" style="display:block;width:112px;max-width:100%;height:auto;margin:0 auto 14px;border:0">
                        <p style="margin:0;color:#ffffff;font-size:20px;font-weight:700;letter-spacing:.6px">AUTORADIO <span style="color:#f5c400">{{ $copy['brand'] }}</span></p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px">
                        @if($preview ?? false)
                            <p style="margin:0 0 22px;padding:10px 14px;background:#f5c400;color:#121212;border-radius:8px;font-size:13px;font-weight:700;text-align:center">{{ $copy['preview'] }}</p>
                        @elseif($order->is_test)
                            <p style="margin:0 0 22px;padding:10px 14px;background:#f5c400;color:#121212;border-radius:8px;font-size:13px;font-weight:700;text-align:center">{{ $copy['test'] }}</p>
                        @endif

                        <h1 style="margin:0 0 16px;color:#ffffff;font-size:28px;line-height:1.25">{{ $copy['thanks'] }}</h1>
                        <p style="margin:0 0 12px;color:#e7e7e7">{{ $copy['hello'] }} {{ $order->customer_name }},<br>{{ $copy['received'] }} <strong style="color:#f5c400">{{ $order->number }}</strong>.</p>
                        @if($order->fulfillment_status === 'cancelled')
                            <p style="margin:0 0 28px;color:#bdbdbd">{{ $copy['cancelled'] }}</p>
                        @else
                            <p style="margin:0 0 28px;color:#bdbdbd">{{ $copy['compatibility'] }}</p>
                        @endif

                        <h2 style="margin:0 0 12px;color:#f5c400;font-size:18px">{{ $copy['summary'] }}</h2>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;text-align:left">
                            <thead>
                                <tr>
                                    <th scope="col" style="padding:10px 8px;border-bottom:1px solid #3a3a3a;color:#f5c400;font-size:12px;text-transform:uppercase">{{ $copy['product'] }}</th>
                                    <th scope="col" style="padding:10px 8px;border-bottom:1px solid #3a3a3a;color:#f5c400;font-size:12px;text-align:center;text-transform:uppercase">{{ $copy['quantity'] }}</th>
                                    <th scope="col" style="padding:10px 8px;border-bottom:1px solid #3a3a3a;color:#f5c400;font-size:12px;text-align:right;text-transform:uppercase">{{ $copy['amount'] }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($order->items as $item)
                                    <tr>
                                        <td style="padding:14px 8px;border-bottom:1px solid #303030;color:#ffffff;font-size:14px">{{ $item->title }}@if($item->variant_title)<br><span style="color:#9f9f9f;font-size:12px">{{ $item->variant_title }}</span>@endif</td>
                                        <td style="padding:14px 8px;border-bottom:1px solid #303030;color:#ffffff;font-size:14px;text-align:center">{{ $item->quantity }}</td>
                                        <td style="padding:14px 8px;border-bottom:1px solid #303030;color:#ffffff;font-size:14px;text-align:right;white-space:nowrap">{{ $money($item->total_amount) }}</td>
                                    </tr>
                                    @if($item->import_total_amount)
                                        <tr><td style="padding:8px;color:#f5c400;font-size:12px">{{ $copy['imports'] }} · {{ $money($item->import_unit_amount) }} × {{ $item->quantity }}</td><td></td><td style="padding:8px;color:#f5c400;font-size:12px;text-align:right">{{ $money($item->import_total_amount) }}</td></tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin-top:18px;background:#202020;border-radius:10px">
                            <tr><td style="padding:14px 16px 3px;color:#bdbdbd;font-size:14px">{{ $copy['products'] }}</td><td style="padding:14px 16px 3px;color:#ffffff;font-size:14px;text-align:right">{{ $money($order->subtotal_amount) }}</td></tr>
                            @if($order->import_amount)<tr><td style="padding:3px 16px;color:#bdbdbd;font-size:14px">{{ $copy['imports'] }}</td><td style="padding:3px 16px;color:#ffffff;font-size:14px;text-align:right">{{ $money($order->import_amount) }}</td></tr>@endif
                            @if($order->discount_amount)<tr><td style="padding:3px 16px;color:#bdbdbd;font-size:14px">{{ $copy['discount'] }}</td><td style="padding:3px 16px;color:#ffffff;font-size:14px;text-align:right">−{{ $money($order->discount_amount) }}</td></tr>@endif
                            <tr><td style="padding:3px 16px;color:#bdbdbd;font-size:14px">{{ $copy['shipping'] }}</td><td style="padding:3px 16px;color:#ffffff;font-size:14px;text-align:right">{{ $order->shipping_amount === 0 ? $copy['free'] : $money($order->shipping_amount) }}</td></tr>
                            <tr><td style="padding:12px 16px 14px;border-top:1px solid #383838;color:#f5c400;font-size:16px;font-weight:700">{{ $copy['total'] }}</td><td style="padding:12px 16px 14px;border-top:1px solid #383838;color:#f5c400;font-size:16px;font-weight:700;text-align:right">{{ $money($order->total_amount) }}</td></tr>
                        </table>

                        <h2 style="margin:28px 0 12px;color:#f5c400;font-size:18px">{{ $copy['address'] }}</h2>
                        <p style="margin:0;padding:16px;background:#202020;border-radius:10px;color:#e7e7e7;font-size:14px">{{ $address['name'] ?? $order->customer_name }}<br>{{ $address['line1'] ?? '' }}<br>@if(!empty($address['line2'])){{ $address['line2'] }}<br>@endif{{ $address['postal_code'] ?? '' }} {{ $address['city'] ?? '' }}@if(!empty($address['province'])) ({{ $address['province'] }})@endif<br>{{ $address['country'] ?? 'IT' }}</p>

                        <p style="margin:28px 0 0;padding-top:18px;border-top:1px solid #303030;color:#858585;font-size:12px;text-align:center">{{ $copy['confirmation'] }} · {{ $order->number }}<br>{{ $copy['not_invoice'] }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
