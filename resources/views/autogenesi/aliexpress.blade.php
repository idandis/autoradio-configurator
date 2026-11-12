<!doctype html>
<html lang="it">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AutoGenesi · AliExpress</title>
<body style="background:#101010;color:#eee;font:16px system-ui;max-width:760px;margin:60px auto;padding:24px">
<h1>AutoGenesi · AliExpress</h1>
<p>Callback HTTPS: <code>{{ $callback }}</code></p>
@if ($connection['connected'])
<p style="color:#6edb96">AliExpress collegato. Token salvato cifrato sul Mac.</p>
<p>Scadenza autorizzazione: {{ \Carbon\Carbon::createFromTimestamp($connection['expires_at'])->timezone('Europe/Rome')->format('d/m/Y H:i') }}.</p>
<h2>Prova ricerca API</h2>
<form method="post" action="{{ route('autogenesi.aliexpress.search') }}">@csrf
<input name="query" required maxlength="150" value="{{ $apiSearch['query'] ?? 'radio Hyundai Kona 2017 2020' }}" style="width:70%;padding:10px"><button>Cerca con API</button>
</form>
<p>Risultati grezzi, destinazione Spagna, prezzi EUR. Compatibilità, origine UE e prezzo della variante da verificare. Questa prova non importa prodotti.</p>
@if ($apiSearch)
<p>{{ count($apiSearch['products']) }} risultati visualizzati su {{ $apiSearch['total'] }} restituiti.</p>
@foreach ($apiSearch['products'] as $p)
<div style="padding:16px 0;border-bottom:1px solid #444">
<a style="color:#9bd" href="{{ $p['url'] }}" target="_blank" rel="noopener noreferrer">{{ $p['title'] }}</a>
<p>{{ $p['price'] !== null ? number_format($p['price'], 2, ',', '.') . ' EUR · prezzo risultato' : 'Prezzo EUR non disponibile' }}</p>
<form method="post" action="{{ route('autogenesi.aliexpress.product') }}">@csrf<input type="hidden" name="product_id" value="{{ $p['id'] }}"><button>Leggi scheda e varianti con API</button></form>
</div>
@endforeach
@endif
@if ($apiProduct)
<h2>{{ $apiProduct['title'] }}</h2>
<a style="color:#9bd" href="{{ $apiProduct['url'] }}" target="_blank" rel="noopener noreferrer">Apri prodotto AliExpress</a>
<p>{{ $apiProduct['description'] }}</p>
<p>Prime {{ count($apiProduct['variants']) }} varianti ricevute, senza selezione automatica.</p>
@foreach ($apiProduct['variants'] as $v)
<p>{{ $v['label'] }} · {{ $v['price'] }} {{ $v['currency'] }}</p>
@endforeach
@endif
@elseif ($failed)
<p>Autorizzazione non concessa. Puoi riprovare.</p>
@endif
@if ($tokenError)
<p style="color:#ffa38c">{{ $tokenError }}</p>
@endif
@if ($pending)
<form method="post" action="{{ route('autogenesi.aliexpress.exchange') }}">@csrf<button>Completa collegamento</button></form>
@endif
@if ($ready)
<form method="post" action="{{ route('autogenesi.aliexpress.authorize') }}">@csrf<button>Autorizza AliExpress</button></form>
@else
<p>Callback pronto. Completa la creazione dell’app AliExpress, poi configura ALIEXPRESS_APP_KEY e ALIEXPRESS_APP_SECRET nel file .env locale.</p>
@endif
<p><a href="{{ route('autogenesi.index') }}" style="color:#9bd">Torna ad AutoGenesi</a></p>
</body>
</html>
