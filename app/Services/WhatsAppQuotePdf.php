<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppQuotePdf
{
    public function create(array $result, string $locale): array
    {
        $id = (string) Str::uuid();
        $reference = app(QuoteNumbers::class)->next();
        $caption = ['it' => 'Ecco il tuo preventivo PDF.', 'es' => 'Aquí tienes tu presupuesto PDF.', 'en' => 'Here is your PDF quote.'][$locale];
        $totalLabel = ! empty($result['installation'])
            ? ['it' => 'Totale preventivo', 'es' => 'Total presupuesto', 'en' => 'Quote total'][$locale]
            : ['it' => 'Totale radio', 'es' => 'Total radio', 'en' => 'Radio total'][$locale];
        $money = fn ($amount) => number_format($amount / 100, 2, ',', '.').' €';
        $path = 'waha/quotes/'.$id.'.pdf';
        if (! Storage::disk('local')->put($path, $this->render($this->html($result, $locale, $reference)))) {
            throw new RuntimeException('Impossibile salvare il PDF del preventivo.');
        }
        $document = ['id' => $id, 'filename' => $reference.'.pdf', 'reference' => $reference,
            'download_label' => ['it' => 'Scarica preventivo PDF', 'es' => 'Descargar presupuesto PDF', 'en' => 'Download quote PDF'][$locale],
            'download_url' => '/dashboard/whatsapp/quotes/'.$id.'/pdf',
            'caption' => $caption."\n".$totalLabel.': '.$money($result['quote']['total_amount'])];
        Cache::store('file')->put('waha-quote-pdf:'.$id, $document, now()->addDay());

        return $document;
    }

    public function html(array $result, string $locale, string $reference): string
    {
        $quote = $result['quote'];
        $vehicle = $result['context']['vehicle'];
        $name = implode(' ', array_filter([$vehicle['brand'] ?? '', $vehicle['model'] ?? '', $vehicle['year'] ?? ''])) ?: trans('configurator.mode.universal_title', [], $locale);
        $t = fn ($key) => e(trans('configurator.'.$key, [], $locale));
        $money = fn ($amount) => number_format($amount / 100, 2, ',', '.').' €';
        $asset = fn ($path) => is_file($path) ? 'data:'.mime_content_type($path).';base64,'.base64_encode(file_get_contents($path)) : '';
        $image = ! empty($vehicle['brand']) && ! empty($vehicle['model']) && ! empty($vehicle['year'])
            ? app(VehicleImageResolver::class)->resolveFilename($vehicle['brand'], $vehicle['model'], (int) $vehicle['year']) : null;
        $image = $image ? $asset(public_path('images/vehicles-dark/'.basename($image))) : '';
        $installation = $result['installation']['amount'] ?? 0;
        $products = $quote['subtotal_amount'] - $installation;
        $online = $quote['total_amount'] - $installation;
        $rows = '';
        $included = '';
        foreach ($quote['items'] as $item) {
            $description = e($item['title'].(! empty($item['variant_title']) ? ' — '.$item['variant_title'] : ''));
            $rows .= '<tr><td>'.$description.'</td><td class="center">'.$item['quantity'].'</td><td class="money">'.$money($item['total_amount'] / $item['quantity']).'</td><td class="money">'.$money($item['total_amount']).'</td></tr>';
            $included .= '<li>'.$description.'</li>';
        }
        $totalRow = fn ($label, $amount) => '<div class="total-row"><div>'.$label.'</div><div class="amount">'.$amount.'</div></div>';
        if ($quote['discount_amount']) {
            $rows .= '<tr><td>'.e($quote['discount_label']).'</td><td class="center">1</td><td class="money">−'.$money($quote['discount_amount']).'</td><td class="money">−'.$money($quote['discount_amount']).'</td></tr>';
        }
        $html = file_get_contents(resource_path('quote/document.html'));
        preg_match_all('/\{\{([a-z_]+)\}\}/', $html, $keys);
        $values = array_fill_keys($keys[1], '');
        $values = array_replace($values, [
            'locale' => $locale, 'reference' => e($reference), 'document_title' => $t('print.document_title'),
            'logo_width' => '95px', 'logo_height' => '68px', 'logo_fit' => 'contain', 'mobile_logo_width' => '70px', 'mobile_logo_height' => '52px',
            'logo_path' => $asset(public_path('images/logo.png')), 'business_name' => 'AUTORADIOCANARIO',
            'tagline' => $t('print.tagline'), 'quote_title' => $t('quote.title'), 'number_label' => $t('print.number'),
            'vehicle_image' => $image ? '<div class="vehicle-band"><img src="'.$image.'" alt="'.e($name).'"></div>' : '',
            'client_label' => $t('print.client'), 'vehicle_label' => $t('print.vehicle'), 'vehicle' => e($name),
            'issuer_label' => $t('print.issued_by'), 'email' => 'Info@AutoRadioCanario.com', 'phone' => '+34 694 259 117',
            'date_label' => $t('print.date'), 'date' => now('Europe/Rome')->format('d/m/Y'),
            'description_label' => $t('print.description'), 'quantity_label' => $t('print.quantity'),
            'unit_price_label' => $t('print.unit_price'), 'amount_label' => $t('print.amount'), 'rows' => $rows,
            'includes_label' => $t('print.includes'), 'included_items' => $included, 'service_notice' => $t('quote.service_amount_notice'),
            'installation_notice' => $installation ? '<p class="direct-notice">'.$t('quote.installation_payment_notice').'</p>' : '',
            'checkout_link' => ! empty($result['payment_url']) ? '<div class="checkout"><strong>'.$t('print.purchase_link').':</strong><br><a href="'.e($result['payment_url']).'">'.e($result['payment_url']).'</a><p class="purchase-authorization">'.$t('print.purchase_authorization').'</p></div>' : '',
            'products_label' => $t('quote.products_online'), 'products_total' => $money($products),
            'discount_row' => $quote['discount_amount'] ? $totalRow(e($quote['discount_label']), '−'.$money($quote['discount_amount'])) : '',
            'installation_row' => $installation ? $totalRow($t('quote.installation_direct'), $money($installation)) : '',
            'estimated_label' => $t('quote.estimated_total'), 'estimated_total' => $money($quote['total_amount']),
            'online_label' => $t('quote.online_total'), 'online_total' => $money($online), 'shipping_notice' => $t('quote.home_delivery'),
            'footer_email' => 'INFO@AUTORADIOCANARIO.COM', 'domain' => 'WWW.AUTORADIOCANARIO.COM',
        ]);

        return preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn ($m) => $values[$m[1]], $html);
    }

    public function render(string $html): string
    {
        $browser = config('waha.pdf_browser');
        if (! $browser || ! is_executable($browser)) {
            throw new RuntimeException('Browser per stampare il preventivo dell’app non disponibile.');
        }
        $dir = Storage::disk('local')->path('waha/quote-print/'.Str::uuid());
        File::ensureDirectoryExists($dir, 0700);
        try {
            file_put_contents($dir.'/quote.html', $html);
            Process::timeout(45)->run([$browser, '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
                '--no-pdf-header-footer', '--user-data-dir='.$dir.'/browser', '--print-to-pdf='.$dir.'/quote.pdf', 'file://'.$dir.'/quote.html'])->throw();
            $pdf = file_get_contents($dir.'/quote.pdf');
            if (! str_starts_with($pdf, '%PDF-')) {
                throw new RuntimeException('Stampa PDF del preventivo non riuscita.');
            }

            return $pdf;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function path(string $id): string
    {
        abort_unless(Str::isUuid($id) && Cache::store('file')->has('waha-quote-pdf:'.$id), 404);
        $path = 'waha/quotes/'.$id.'.pdf';
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->path($path);
    }

    public function document(string $id): array
    {
        $this->path($id);

        return Cache::store('file')->get('waha-quote-pdf:'.$id);
    }
}
