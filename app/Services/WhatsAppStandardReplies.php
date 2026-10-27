<?php

namespace App\Services;

use Illuminate\Support\Str;

class WhatsAppStandardReplies
{
    public function shippingPolicy(string $locale): string
    {
        return [
            'it' => 'Alle Canarie il prezzo comprende tutto: spedizione gratuita e costi di importazione inclusi. Fuori dalle Canarie, le spese di spedizione vengono calcolate automaticamente durante l’ordine.',
            'es' => 'En Canarias, el precio lo incluye todo: envío gratuito y gastos de importación incluidos. Fuera de Canarias, los gastos de envío se calculan automáticamente al realizar el pedido.',
            'en' => 'In the Canary Islands, the price includes everything: free shipping and import costs. Outside the Canary Islands, shipping costs are calculated automatically at checkout.',
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
            'Contatti' => 'contatti|contactos|contacts|numero di telefono|número de teléfono|phone number',
            'Pagamenti' => 'pagament[oi]|pagare|rate|rateale|paypal|bonific[oi]|cart[ae] di credito|cart[ae] di debito|pago|pagos|pagar|plazos|cuotas|transferencia bancaria|tarjetas?|payments?|pay|instalments?|installments?|bank transfers?|credit cards?|debit cards?',
            'CarPlay' => 'car\s*play', 'Android Auto' => 'android\s*auto',
            'Formato DIN' => '[12][ -]?din|formato din|din format',
            'GPS' => 'gps|navigatore|navigazione|navegador|navigation',
            '4G / SIM' => '4\s*g|sim|scheda dati|tarjeta sim',
            'Bluetooth' => 'bluetooth', 'Wi-Fi' => 'wi[ -]?fi|hotspot',
            'Garanzia' => 'garanzia|garantía|warranty',
            'Spedizione' => 'spedizion[ei]|spedite|spedisci|consegnate|consegn[ae]|env[ií]os?|envi[aá]is|env[ií]an|mand[aá]is|shipping|delivery|deliver|ship',
            'Installazione' => 'installazion[ei]|installator[ei]|montaggi[oi]|installate|montate|instalaci[oó]n|instalaciones|instalador(?:es)?|instal[aá]is|instalan|mont[aá]is|montan|installations?|installers?|(?:do you|can you) (?:also )?(?:install|fit)',
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
