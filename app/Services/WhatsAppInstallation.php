<?php

namespace App\Services;

use App\Models\InstallationZone;

class WhatsAppInstallation
{
    public function respond(string $text, array $history, array $vehicle, array $state, string $locale): ?array
    {
        if (($state['pending'] ?? false) || app(WhatsAppQuote::class)->requested($text)) {
            return null;
        }
        $last = collect($history)->where('role', 'assistant')->last()['content'] ?? '';
        $waiting = ($state['installation_pending'] ?? false) || (bool) preg_match('/(?:zona|area|postal|codice postale|c[oó]digo postal).*\?/ui', $last);
        $installation = in_array('Installazione', app(WhatsAppStandardReplies::class)->topics($text), true);
        if (! $waiting && ! $installation) {
            return null;
        }
        if ($waiting && ! $installation && preg_match('/\b(camera|c[aá]mara|telecamera|retrocamera|radio|autoradio|pantalla|schermo|preventivo|presupuesto|quote|carplay|bluetooth|gps|garanzia|garant[ií]a|warranty|spedizione|env[ií]o|shipping)\b/ui', $text)) return null;
        $zones = InstallationZone::where('active', true)->with(['postalCodes', 'services'])->get();
        $location = $text;
        if ($installation && ! $waiting && ! preg_match('/\b\d{5}\b/', $text) && ! $zones->contains(fn ($z) => $z->matchesLocation($text))) {
            foreach (array_reverse($history) as $message) {
                if (($message['role'] ?? '') !== 'user') continue;
                if ($zones->contains(fn ($z) => $z->matchesLocation($message['content']))
                    || preg_match('/\b(?:provincia|province|sono a|estoy en|i am in|based in|scrivo da)\b|\b(?:cap|cp|postal code|c[oó]digo postal)\s*\d{5}\b/ui', $message['content'])) {
                    $location = $message['content'];
                    $waiting = true;
                    break;
                }
            }
        }
        preg_match('/\b\d{5}\b/', $location, $postal);
        $matches = $zones->filter(fn ($z) => $z->matchesLocation($location)
            || ($postal && $z->postalCodes->contains(fn ($p) => $p->postal_code_from <= $postal[0] && $p->postal_code_to >= $postal[0])));
        $zone = $matches->count() === 1 ? $matches->first() : null;
        if (! $zone && $matches->isEmpty() && $installation && ! $postal) {
            $zone = $zones->firstWhere('id', $state['installation_zone'] ?? 0);
        }
        $base = ['context' => ['vehicle' => $vehicle, 'matching_specific_screens' => 0],
            'resume_automation' => true, 'technical' => [], 'signature' => hash('sha256', json_encode($vehicle))];
        if (! $zone && ! $waiting) {
            $rules = app(WhatsAppAssistantSettings::class)->get();
            $reply = app(WhatsAppStandardReplies::class)->match($text,
                array_values(array_filter($rules['standard_replies'], fn ($r) => $r['category'] === 'general')), $locale);
            if ($reply === null) return null;

            return $base + ['mode' => 'standard_general', 'text' => $reply,
                'quote_state' => array_replace($state, ['installation_pending' => true])];
        }
        if (! $zone) {
            if ($postal) {
                return $base + ['mode' => 'handoff', 'handoff_reason' => 'Verificare installatore per CAP '.$postal[0], 'text' => [
                    'it' => 'Verifichiamo un installatore per la tua zona. Verrai contattato il prima possibile.',
                    'es' => 'Comprobaremos un instalador para tu zona. Te contactaremos lo antes posible.',
                    'en' => 'We will check for an installer in your area and contact you as soon as possible.',
                ][$locale]];
            }

            return $base + ['mode' => 'standard_general', 'text' => [
                'it' => 'Qual è il codice postale della tua zona? Scrivi le 5 cifre, ad esempio 35200.', 'es' => '¿Cuál es el código postal de tu zona? Escribe las 5 cifras, por ejemplo 35200.', 'en' => 'What is your area’s postal code? Write the 5 digits, for example 35200.',
            ][$locale], 'quote_state' => array_replace($state, ['installation_pending' => true])];
        }
        $lines = [[
            'it' => 'Per '.$zone->name.' sono disponibili queste installazioni:',
            'es' => 'Para '.$zone->name.' están disponibles estas instalaciones:',
            'en' => 'These installation services are available in '.$zone->name.':',
        ][$locale]];
        $services = $zone->services->filter(fn ($s) => preg_match('/^(?:pantalla|radio|schermo|screen)$/i', trim($s->name))
            || preg_match('/^(?:pantalla|radio|schermo|screen)\s*\+\s*(?:camara|cámara|camera|rear camera|telecamera)\s+(?:trasera|posteriore|rear)$/i', trim($s->name)));
        foreach (($services->isNotEmpty() ? $services : $zone->services)->take(3) as $service) {
            $lines[] = '• '.$service->localizedName($locale).' — '.number_format((float) $service->price, 2, ',', '.').' €';
        }
        if ($zone->installer_address) {
            $lines[] = $zone->installer_address;
        }
        if ($zone->installer_phone) {
            $lines[] = $zone->installer_phone;
        }
        if ($zone->services->isEmpty()) {
            return $base + ['mode' => 'handoff', 'handoff_reason' => 'Verificare disponibilità installatore in '.$zone->name,
                'text' => ['it' => 'Verifichiamo l’installazione nella tua zona. Verrai ricontattato.', 'es' => 'Verificaremos la instalación en tu zona. Te contactaremos.', 'en' => 'We will check installation in your area and contact you.'][$locale]];
        }

        return $base + ['mode' => 'standard_general', 'text' => implode("\n", $lines), 'quote_state' => array_replace($state, ['installation_zone' => $zone->id, 'installation_pending' => false])];
    }
}
