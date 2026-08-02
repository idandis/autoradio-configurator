<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuova richiesta autoradio</title>
</head>
<body style="margin:0;background:#f4f4f5;font-family:Arial,sans-serif;color:#18181b">
    <div style="max-width:640px;margin:0 auto;padding:32px 16px">
        <div style="background:#121212;padding:24px;border-radius:12px 12px 0 0">
            <div style="font-size:12px;font-weight:700;letter-spacing:2px;color:#fbbf24">AUTORADIOCANARIO</div>
            <h1 style="margin:10px 0 0;font-size:24px;color:#ffffff">Nuova richiesta autoradio</h1>
        </div>

        <div style="background:#ffffff;padding:24px;border-radius:0 0 12px 12px">
            <p style="margin:0 0 20px;color:#52525b">Un cliente ha compilato il modulo “Déjanos buscar tu autoradio ideal”.</p>

            <table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px">
                @foreach ([
                    'Nome' => $requestData['first_name'].' '.$requestData['last_name'],
                    'Email' => $requestData['email'],
                    'Telefono' => $requestData['phone'],
                    'Provincia' => $requestData['province'],
                    'Marca' => $requestData['brand'],
                    'Modello' => $requestData['model'],
                    'Anno' => $requestData['year'],
                ] as $label => $value)
                    <tr>
                        <th style="width:120px;padding:10px 12px;border-bottom:1px solid #e4e4e7;text-align:left;color:#71717a">{{ $label }}</th>
                        <td style="padding:10px 12px;border-bottom:1px solid #e4e4e7;font-weight:600">{{ $value }}</td>
                    </tr>
                @endforeach
            </table>

            <div style="margin-top:22px">
                <div style="margin-bottom:7px;font-size:13px;font-weight:700;color:#71717a">COMMENTO</div>
                <div style="padding:14px;border-radius:8px;background:#f4f4f5;white-space:pre-wrap">{{ ($requestData['comment'] ?? null) ?: 'Nessun commento' }}</div>
            </div>

            <p style="margin:22px 0 0;font-size:13px;color:#71717a">Puoi rispondere direttamente a questa email per contattare il cliente.</p>
        </div>
    </div>
</body>
</html>
