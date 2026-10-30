<?php

namespace App\Services;

use Illuminate\Support\Str;

class WhatsAppStandardReplies
{
    public function taxQuestion(string $text): bool
    {
        return (bool) preg_match('/\b(?:iva|igic|vat)\b.{0,65}(?:inclu|deduc|descont|resta|cambi|chang|subtract|deduct)|(?:inclu|deduc|descont|resta|subtract|deduct).{0,45}\b(?:iva|igic|vat)\b|\b(?:iva|igic|vat)\s*\?/ui', $text);
    }

    public function taxPolicy(string $locale): string
    {
        return [
            'it' => 'I prezzi indicati includono già le imposte applicabili: IGIC per le Canarie e IVA per il resto della Spagna. Non occorre sottrarre l’IVA dal prezzo per le Canarie.',
            'es' => 'Los precios indicados ya incluyen los impuestos correspondientes: IGIC para Canarias e IVA para el resto de España. No hay que descontar el IVA del precio para Canarias.',
            'en' => 'The listed prices already include the applicable taxes: IGIC for the Canary Islands and VAT for the rest of Spain. VAT does not need to be deducted from the price for the Canary Islands.',
        ][$locale];
    }

    public function wiringProblem(string $text): bool
    {
        return (bool) preg_match('/\b(cabl[ei]s?|cablaggi\w*|conexi[oó]n|conector\w*|collegament\w*|connett\w*|connect(?:ion|or)s?|wir(?:e|es|ing)|antenna|antena)\b/ui', $text)
            && (bool) preg_match('/\b(manc\w*|falta\w*|missing|rotto|rotti|roto|broken|tagliat\w*|cortad\w*|cut|risolv\w*|resolver\w*|solucion\w*|fix|solve|problem\w*|non funziona|no funciona|not working)\b/ui', $text);
    }

    public function considering(string $text): bool
    {
        return ! preg_match('/[?¿]|\b(prezz\w*|precios?|prices?|cu[aá]nto|quanto|cost\w*|cuesta|install\w*|instal\w*|montagg\w*|sped\w*|env[ií]\w*|shipping|delivery|consegn\w*|compatib\w*|stock|disponib\w*|garant\w*|warranty|operatore|operador|operator)\b/ui', $text)
            && (bool) preg_match('/\b(se (?:mi )?decido|quando (?:mi )?decido|(?:poi|pi[uù] avanti) (?:ti|vi) (?:ri)?scrivo|(?:ti|vi) (?:ri)?scriver[oò]|si me decido|cuando (?:me )?decida|(?:te|os|les) (?:vuelvo|volver[eé]) a escribir|(?:if|when) I decide|I(?:[’\x27]ll| will) (?:write|contact|message|get back to) (?:you|again)|ci penso|ci sto pensando|sto (?:ancora )?(?:pensando|valutando)|non ho fretta|devo (?:ancora )?decidere|(?:sigo|estoy|seguimos) (?:todav[ií]a )?pensando|me lo (?:voy a )?pensar[eé]|no tengo prisa|a[uú]n no (?:me )?he decidido|still (?:thinking|considering|deciding)|think (?:it )?over|no (?:rush|hurry))\b/ui', $text);
    }

    public function deliveryQuestion(string $text): bool
    {
        return (bool) preg_match('/\b(?:cu[aá]nto|cu[aá]ntos).{0,35}\b(?:tarda\w*|demora\w*)|\b(?:cu[aá]ndo|quando).{0,25}\b(?:llega\w*|arriva\w*)|\bquanto.{0,25}\b(?:ci vuole|impiega\w*|tempo)|\bin quanto.{0,20}\barriva\w*|\bhow long.{0,45}\b(?:arriv\w*|deliver\w*|take|ship\w*)|\bwhen.{0,30}\b(?:arriv\w*|deliver\w*)|\b(?:tempi di consegna|plazo de entrega|delivery time|lead time)\b/ui', $text)
            && ! preg_match('/\b(buscar\w*|cercar\w*|search\w*|tracking|seguimiento|ritardo|retraso|gi[aà] ordinat[ao]|ya (?:he )?pedid[ao]|already ordered|mi pedido|mio ordine|my order|order number|numero ordine)\b/ui', $text);
    }

    public function deliveryPolicy(string $locale): string
    {
        return ['it' => 'Il tempo di consegna previsto è di 10–15 giorni.',
            'es' => 'El plazo de entrega estimado es de 10–15 días.',
            'en' => 'The estimated delivery time is 10–15 days.'][$locale];
    }

    public function availabilityQuestion(string $text): bool
    {
        return (bool) preg_match('/\bstock\b|\bdisponib(?:ile|ili|ilidad|le|les)\b|\b(?:pedirla|pedirlo|ordinarl[ao]|order it)\b|\b(?:tempi di consegna|plazo de entrega|delivery time|lead time)\b/ui', $text)
            && ! preg_match('/\b(tracking|seguimiento|ritardo|retraso|gi[aà] ordinat[ao]|ya (?:he )?pedid[ao]|already ordered)\b/ui', $text);
    }

    public function availabilityPolicy(string $locale): string
    {
        return [
            'it' => 'Normalmente dobbiamo ordinare la radio al nostro fornitore. Il tempo di consegna previsto è di 10–15 giorni.',
            'es' => 'Normalmente tenemos que pedir la radio a nuestro proveedor. El plazo de entrega estimado es de 10–15 días.',
            'en' => 'We normally need to order the radio from our supplier. The estimated delivery time is 10–15 days.',
        ][$locale];
    }

    public function shippingDestination(array $messages): ?bool
    {
        $zones = \App\Models\InstallationZone::where('active', true)->get();
        foreach (array_reverse($messages) as $message) {
            $text = Str::lower(Str::ascii($message));
            if (preg_match('/fuera de canarias|fuori dalle canarie|outside (?:the )?canary islands/', $text)) return false;
            if (preg_match('/\b(canarias|canarie|canary islands|gran canaria|las palmas|tenerife|lanzarote|fuerteventura|la palma|la gomera|el hierro)\b/', $text)) return true;
            if (preg_match('/\b(35|38)\d{3}\b/', $text)) return true;
            if (preg_match('/\b(madrid|barcelona|valencia|sevilla|peninsula|italia|italy|francia|france|germany|alemania|portugal|england|inghilterra|inglaterra|united kingdom)\b/', $text)) return false;
            $zone = $zones->first(fn ($z) => $z->matchesLocation($message));
            if ($zone) {
                $label = Str::lower(Str::ascii($zone->name));
                return (bool) preg_match('/canari|las palmas|tenerife|lanzarote|fuerteventura|telde|arucas|la palma|la gomera|el hierro/', $label);
            }
            if (preg_match('/\b(?:estoy en|soy de|vivo en|envio a|sono a|sono di|vivo a|spedizione a|i am in|i live in|shipping to)\s+\p{L}{2,}/u', $text)) return false;
        }

        return null;
    }

    public function shippingPolicy(string $locale, array $messages = []): string
    {
        $destination = $this->shippingDestination($messages);
        if ($destination === null) return [
            'it' => 'In quale località vuoi ricevere la spedizione?',
            'es' => '¿En qué localidad quieres recibir el envío?',
            'en' => 'Which town would you like the order shipped to?',
        ][$locale];
        if ($destination) return [
            'it' => 'Il prezzo include spedizione gratuita e costi di importazione.',
            'es' => 'El precio incluye envío gratuito y gastos de importación.',
            'en' => 'The price includes free shipping and import costs.',
        ][$locale];

        return [
            'it' => 'Le spese di spedizione vengono calcolate automaticamente nella schermata di spedizione durante l’ordine.',
            'es' => 'Los gastos de envío se calculan automáticamente en la pantalla de envío al realizar el pedido.',
            'en' => 'Shipping costs are calculated automatically on the shipping screen at checkout.',
        ][$locale];
    }

    private function keywords(string $text): array
    {
        $text = Str::lower(Str::ascii($text));
        $stop = explode(' ', 'ciao buongiorno buonasera salve hola buenos buenas dias tardes noches hello good morning afternoon evening grazie gracias thanks please favore avete fate posso potete possibile vorrei sapere quanto come quando dove quale quali della delle dello degli alla alle anche questa questo radio autoradio pantalla screen coche auto car vostro vostra vostro vostro solo sono siete con per che una uno del los las por para que hay como donde puedo puede pueden tiene tienen quiero saber hacen habeis the and for with your does have has can could would you offer provide about this that what which how much is it do me my i a en un de la le el il lo e o si no yes not non senza without sin');

        return array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', $text), fn ($w) => strlen($w) >= 3 && ! in_array($w, $stop, true))));
    }

    private function similar(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (is_numeric($a) || is_numeric($b) || min(strlen($a), strlen($b)) < 5) {
            return false;
        }
        if (strlen($a) === strlen($b)) {
            for ($i = 0; $i < strlen($a) - 1; $i++) {
                $copy = $a;
                [$copy[$i], $copy[$i + 1]] = [$copy[$i + 1], $copy[$i]];
                if ($copy === $b) {
                    return true;
                }
            }
        }

        return levenshtein($a, $b) <= 1
            || (min(strlen($a), strlen($b)) >= 7 && substr($a, 0, -2) === substr($b, 0, -2));
    }

    private function related(string $question, string $example): bool
    {
        $expected = $this->keywords($example);
        $actual = $this->keywords($question);
        if (! $expected || ! $actual) {
            return false;
        }
        $hits = count(array_filter($expected, fn ($w) => collect($actual)->contains(fn ($a) => $this->similar($a, $w))));

        return $hits === count($expected) && $hits / count($actual) >= 0.6;
    }

    public function topics(string $text): array
    {
        $patterns = [
            'Negozio' => 'negozio|tienda|shop|store|sede',
            'Indirizzo' => 'indirizzo|dirección|direccion|address|dove siete|dónde estáis|donde estais|where are you located',
            'Orari' => 'orari|orario|horario|horarios|opening hours|when.*open',
            'Contatti' => 'contatti|contactos|contacts|numero di telefono|número de teléfono|phone number|(?:e[ -]?)?mail|correo(?: electr[oó]nico)?|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}',
            'Pagamenti' => 'pagament[oi]|pagare|rate|rateale|paypal|bonific[oi]|cart[ae] di credito|cart[ae] di debito|pago|pagos|pagar|plazos|cuotas|transferencia bancaria|tarjetas?|payments?|pay|instalments?|installments?|bank transfers?|credit cards?|debit cards?',
            'CarPlay' => 'car\s*play', 'Android Auto' => 'android\s*auto',
            'Formato DIN' => '[12][ -]?din|formato din|din format',
            'GPS' => 'gps|navigatore|navigazione|navegador|navigation',
            '4G / SIM' => '4\s*g|sim|scheda dati|tarjeta sim',
            'Bluetooth' => 'bluetooth', 'Wi-Fi' => 'wi[ -]?fi|hotspot',
            'Garanzia' => 'garanzia|garantía|warranty',
            'Spedizione' => 'spedizion[ei]|spedite|spedisci|consegnate|consegn[ae]|env[ií]os?|envi[aá]is|env[ií]an|mand[aá]is|shipping|delivery|deliver|ship',
            'Installazione' => 'instal{1,2}azion[ei]|instal{1,2}ator[ei]|montaggi[oi]|instal{1,2}ate|montate|(?:instal{1,2}|mont)(?:arl[aoei]|are|aggio|aggi)|instalaci[oó]n|instalaciones|instalador(?:es)?|(?:instal{1,2}|mont)(?:a|as|an|amos|[aá]is|ar|arl[ao]s?)|installations?|installers?|(?:do|can|could|would|will) you (?:also )?(?:install|fit)',
            'Comandi al volante' => 'comandi al volante|mandos del volante|steering wheel',
            'Retrocamera' => 'retrocamera|telecamera posteriore|cámara trasera|cámara de marcha atrás|rear camera|reversing camera',
            'Radio FM' => 'fm', 'USB' => 'usb',
            'Equalizzatore' => 'equalizzatore|regolazion[ei] audio|ecualizador|equalizer|equaliser|sound settings|ajustes de sonido',
            'Lingue' => 'lingu[ae]|idiomas?|languages?|men[uùs]+',
            'Schermo touch' => 'touchscreen|touch[ -]?screen|schermo touch|pantalla táctil|táctil',
            'Comandi vocali' => 'comandi vocali|assistente vocale|mandos de voz|comandos de voz|asistente de voz|voice commands?|voice assistant',
            'Funzioni originali' => '(?:funzioni|funzionalità)(?:\s+\pL+){0,2}\s+originali|funciones(?:\s+\pL+){0,2}\s+originales|(?:factory|original|oem)(?:\s+(?:car|vehicle|radio|head\s+unit))?\s+(?:functions?|features?)',
        ];

        return array_keys(array_filter($patterns, fn ($p) => preg_match('/(?<![\pL\pN])(?:'.$p.')(?![\pL\pN])/ui', $text)));
    }

    public function match(string $text, array $replies, string $locale = 'it'): ?string
    {
        if (preg_match('/\b(modific\w*|cambiar\w*|change|cancel\w*|annull\w*)\b.{0,40}\b(pagament\w*|pago|payment)\b/ui', $text)) {
            return null;
        }
        if (preg_match('/\b(prezz\w*|precios?|prices?|costa|costo|cuesta|cost|link|enlace|compatibil\w*|specific\w*|adatt\w*|modello|model|versione|version|variante|variant|ram|core|gb|pollici|inch)\b|\b(?:19|20)\d{2}\b/ui', $text)) {
            return null;
        }
        $normalize = fn ($v) => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($v))));
        $question = $normalize($text);
        $custom = array_values(array_filter($replies, function ($r) use ($normalize, $question) {
            if (! $r['enabled']) {
                return false;
            }
            foreach (preg_split('/\R/u', implode("\n", $r['questions'] ?? [])) as $example) {
                if ($normalize($example) !== '' && ($normalize($example) === $question || $this->related($question, $example))) {
                    return true;
                }
            }
            $title = $normalize($r['title'] ?? '');

            return $title !== '' && ((str_word_count($title) >= 2 && str_contains(' '.$question.' ', ' '.$title.' ')) || $this->related($question, $title));
        }));
        if ($custom) {
            return count($custom) === 1 ? (trim($custom[0]['answers'][$locale] ?? '') ?: null) : null;
        }
        $topics = $this->topics($text);
        if (! $topics) {
            return null;
        }
        $answers = [];
        foreach ($topics as $topic) {
            $matches = array_values(array_filter($replies, fn ($r) => $r['enabled'] && in_array($topic, isset($r['topic']) ? [$r['topic']] : $this->topics(($r['title'] ?? '').' '.implode(' ', $r['answers'] ?? ['it' => $r['answer'] ?? ''])), true)));
            $dedicated = array_values(array_filter($matches, fn ($r) => ($r['topic'] ?? null) === $topic || $normalize($r['title'] ?? '') === $normalize($topic)));
            if ($dedicated) {
                $matches = $dedicated;
            }
            if (count($matches) !== 1) {
                return null;
            }
            $answer = trim(($matches[0]['answers'] ?? ['it' => $matches[0]['answer'] ?? ''])[$locale] ?? '');
            if ($answer === '') {
                return null;
            }
            $answers[] = $answer;
        }

        return implode("\n\n", array_unique($answers));
    }
}
