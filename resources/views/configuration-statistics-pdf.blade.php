<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Statistiche configuratore</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        body { font-family: Arial, sans-serif; color: #222; font-size: 9px; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        p { margin: 0 0 14px; color: #666; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bbb; padding: 5px; text-align: left; vertical-align: top; }
        th { background: #eee; }
        .money { text-align: right; white-space: nowrap; }
    </style>
</head>
<body>
    <h1>Statistiche configuratore</h1>
    <p>Generato il {{ $generatedAt->format('d/m/Y H:i:s') }} — {{ $events->count() }} eventi</p>
    <table>
        <thead><tr><th>Data/Ora</th><th>Evento</th><th>Veicolo</th><th>Prodotto</th><th>Variante</th><th>Valore</th><th>Installazione</th><th>Camera</th><th>CAP/Zona</th><th>Lingua</th></tr></thead>
        <tbody>
        @forelse ($events as $event)
            <tr>
                <td>{{ $event->created_at?->format('d/m/Y H:i:s') }}</td>
                <td>{{ $event->event_type }}</td>
                <td>{{ collect([$event->brand, $event->model, $event->year])->filter()->join(' ') ?: '—' }}</td>
                <td>{{ $event->product_title ?: '—' }}</td>
                <td>{{ $event->variant_title ?: '—' }}</td>
                <td class="money">{{ number_format((float) ($event->configuration_value ?? $event->product_price ?? 0), 2, ',', '.') }} €</td>
                <td>{{ $event->installation_selected ? ($event->installation_type ?: 'Sì') : 'No' }}</td>
                <td>{{ $event->camera_selected ? 'Sì' : 'No' }}</td>
                <td>{{ collect([$event->postal_code, $event->service_zone])->filter()->join(' / ') ?: '—' }}</td>
                <td>{{ strtoupper($event->language ?: '—') }}</td>
            </tr>
        @empty
            <tr><td colspan="10">Nessun evento trovato.</td></tr>
        @endforelse
        </tbody>
    </table>
    <script>window.addEventListener('load', () => window.print());</script>
</body>
</html>
