<?php

namespace App\Services;

use App\Http\Controllers\ConfiguratorController;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class WhatsAppMissingVehicleForm
{
    public function step(string $text, array $vehicle, array $state, string $phone, string $locale): array
    {
        if (! empty($state['data'])) {
            return ['text' => [
                'it' => 'La ricerca per la tua auto è già aperta. Un operatore verificherà anche questa domanda e ti ricontatterà.',
                'es' => 'La búsqueda para tu coche ya está abierta. Un operador revisará también esta consulta y te contactará.',
                'en' => 'The search for your car is already open. A human operator will also review this question and contact you.',
            ][$locale], 'mode' => 'handoff', 'handoff_reason' => 'Domanda successiva a richiesta radio non trovata: '.$text,
                'context' => ['vehicle' => $vehicle, 'matching_specific_screens' => 0],
                'form' => $state, 'signature' => hash('sha256', json_encode($vehicle))];
        }
        $data = array_replace(['first_name' => '', 'last_name' => '', 'email' => '', 'province' => ''], $vehicle, [
            'phone' => $phone,
            'comment' => $state['data']['comment'] ?? "Richiesta radio specifica da WhatsApp:\n".$text,
        ]);
        $question = [
            'it' => 'Stiamo cercando lo schermo compatibile con il tuo modello. Verrai contattato il prima possibile.',
            'es' => 'Estamos buscando la pantalla compatible con tu modelo. Te contactaremos lo antes posible.',
            'en' => 'We are looking for a screen compatible with your model. We will contact you as soon as possible.',
        ][$locale];

        return ['text' => $question, 'mode' => 'missing_submitted',
            'context' => ['vehicle' => $vehicle, 'matching_specific_screens' => 0],
            'form' => ['data' => $data, 'field' => null, 'locale' => $locale], 'signature' => hash('sha256', json_encode($vehicle))];
    }

    public function submit(array $data): void
    {
        $key = 'waha-missing-form:'.hash('sha256', json_encode(array_intersect_key($data, array_flip(['phone', 'brand', 'model', 'year']))));
        if (! Cache::store('file')->add($key, true, now()->addHours(12))) {
            return;
        }
        try {
            $response = app(ConfiguratorController::class)->storeMissingVehicle($data);
            if ($response->getStatusCode() >= 400) {
                throw new RuntimeException('Invio del modulo non riuscito.');
            }
        } catch (\Throwable $e) {
            Cache::store('file')->forget($key);
            throw $e;
        }
    }
}
