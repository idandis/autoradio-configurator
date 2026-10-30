<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WhatsAppAssistantSettings
{
    public function defaults(): array
    {
        return [
            'enabled' => (bool) config('waha.test_enabled'),
            'test_number' => preg_replace('/\D/', '', (string) config('waha.test_number')),
            'additional_test_numbers' => [],
            'fallback_language' => 'es', 'reply_delay_seconds' => 4, 'language' => 'auto', 'tone' => 'diretto', 'max_words' => 35, 'max_questions' => 1,
            'direct_offers' => true, 'max_products' => 2, 'show_prices' => true, 'show_links' => true,
            'price_disclaimer' => true, 'custom_rules' => '',
            'handoff_keywords' => "operatore\noperador\noperator\nassistenza umana\nreclamo\nrimborso\nhuman agent\nagente humano",
            'handoff_on_missing' => false,
            'standard_replies' => [],
            'welcome_messages' => [
                'it' => 'Sono l’assistente AI di AutoRadioCanario. Posso aiutarti con radio, versioni, prezzi, spedizioni e installazione. In qualsiasi momento puoi scrivere OPERATORE per parlare con una persona: verrai ricontattato entro 24 ore.',
                'es' => 'Soy el asistente de IA de AutoRadioCanario. Puedo ayudarte con radios, versiones, precios, envíos e instalación. En cualquier momento puedes escribir OPERADOR para hablar con una persona: te contactaremos en un plazo de 24 horas.',
                'en' => 'I’m AutoRadioCanario’s AI assistant. I can help with radios, versions, prices, shipping and installation. You can write OPERATOR at any time to speak with a person: we’ll contact you within 24 hours.',
            ],
        ];
    }

    public function get(): array
    {
        $saved = Schema::hasTable('whatsapp_assistant_settings')
            ? DB::table('whatsapp_assistant_settings')->where('id', 1)->value('rules') : null;

        $rules = array_replace($this->defaults(), $saved ? json_decode($saved, true, 512, JSON_THROW_ON_ERROR) : []);
        $rules['welcome_messages'] = array_replace($this->defaults()['welcome_messages'], $rules['welcome_messages']);
        $rules['standard_replies'] = array_map(function ($r) {
            $topics = isset($r['topic']) ? [$r['topic']] : app(WhatsAppStandardReplies::class)->topics(($r['title'] ?? '').' '.implode(' ', $r['answers'] ?? [$r['answer'] ?? '']));
            $generalTopics = ['Negozio', 'Indirizzo', 'Orari', 'Contatti', 'Garanzia', 'Spedizione', 'Installazione', 'Formato DIN', 'Pagamenti'];
            $r['category'] = $r['category'] ?? ($topics && ! array_diff($topics, $generalTopics) ? 'general' : 'product');
            $r['questions'] = array_replace(['it' => '', 'es' => '', 'en' => ''], $r['questions'] ?? []);
            $r['title'] = $r['title'] ?? $r['topic'] ?? '';
            $r['answers'] = array_replace(['it' => $r['answer'] ?? '', 'es' => '', 'en' => ''], $r['answers'] ?? []);
            unset($r['answer']);

            return $r;
        }, $rules['standard_replies']);

        return $rules;
    }

    public function save(array $rules): void
    {
        $now = now();
        DB::table('whatsapp_assistant_settings')->upsert([
            ['id' => 1, 'rules' => json_encode(array_replace($this->get(), $rules), JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now],
        ], ['id'], ['rules', 'updated_at']);
    }

    public function suffix(?string $number = null): string
    {
        return hash('sha256', config('waha.session').($number ?? $this->get()['test_number']).'@c.us');
    }

    public function reset(): void
    {
        app(WhatsAppPhotoReview::class)->discard(Cache::store('file')->get('waha-test-photos:'.$this->suffix(), []));
        foreach (['history', 'vehicle', 'offer', 'handoff', 'form', 'technical', 'quote', 'photos'] as $kind) {
            Cache::store('file')->forget('waha-test-'.$kind.':'.$this->suffix());
        }
        Cache::store('file')->put('waha-test-revision:'.$this->suffix(), bin2hex(random_bytes(12)), now()->addDay());
    }

    public function locale(array $messages): string
    {
        $rules = $this->get();
        if ($rules['language'] !== 'auto') {
            return $rules['language'];
        }
        $locale = $rules['fallback_language'];
        foreach ($messages as $message) {
            $normalize = fn ($v) => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($v))));
            $languages = [];
            foreach ($rules['standard_replies'] as $reply) {
                if (! $reply['enabled']) {
                    continue;
                }
                foreach ($reply['questions'] as $lang => $examples) {
                    foreach (preg_split('/\R/u', $examples ?? '') as $example) {
                        if ($normalize($example) !== '' && $normalize($example) === $normalize($message)) {
                            $languages[] = $lang;
                        }
                    }
                }
            }
            if (count(array_unique($languages)) === 1) {
                $locale = $languages[0];

                continue;
            }
            $patterns = [
                'es' => 'cu[aá]nto|tarda|llegar|operador|cu[aá]l|vuestro|vuestra|correo|hola|buenos\s+d[ií]as|buenas(?:\s+(?:tardes|noches))?|quiero|busco|tengo|tenéis|precio|pantalla|gracias|tiene|tienen|lleva|incluye|garantía|envío|mantienen|conserva|funciones|puede|puedo|cómo|qué|dónde|donde|horario|presupuesto|instalaciones|instalación|instala|instalas|instalan|instalar(?:l[ao]s?)?|usted|ustedes|hacéis|instaláis|envios|enviáis|envían',
                'en' => 'operator|hello|hi|good morning|good afternoon|good evening|looking|price|thanks|my car|does|has|have|supports|warranty|shipping|can|will|retain|retains|keep|keeps|functions?|features?|what|how|is|are|do|quote|quotation|ship|shipping|deliver|delivery',
                'it' => 'quanto|consegna|arrivare|operatore|quale|qual|vostr[aoie]|indirizzo|scrivervi|potete|installate|installarla|installarlo|montarla|montarlo|hai|universali|universalil|universale|al caso mio|ciao|buongiorno|buonasera|salve|pagamento|rateale|posso|vorrei|cerco|avete|prezzo|grazie|mantiene|mantengono|funzioni|funzionalità|ha il|della mia|della tua|la mia auto|dove|orari|preventivo|faresti|cosa significa|che significa|spedite|spedizioni|spedizione|consegnate',
            ];
            $scores = [];
            foreach ($patterns as $lang => $pattern) {
                $scores[$lang] = preg_match_all('/\b('.$pattern.')\b/ui', $message);
            }
            if ($scores['es'] > 0 && preg_match('/\bfunciones\b/ui', $message)) {
                $scores['es']++;
            }
            $best = max($scores);
            $winners = array_keys($scores, $best, true);
            if ($best > 0 && count($winners) === 1) {
                $locale = $winners[0];
            }

        }

        return $locale;
    }

    public function runtime(): array
    {
        $state = Cache::store('file')->get('waha-test-runtime', []);
        $alive = ($state['at'] ?? 0) > time() - (($state['state'] ?? '') === 'busy' ? 120 : 20);

        return [
            'running' => $alive && ($state['state'] ?? '') !== 'stopped',
            'state' => $alive ? ($state['state'] ?? 'stopped') : 'stopped',
            'replies' => $state['replies'] ?? 0, 'expires_at' => $state['expires_at'] ?? null,
            'number' => $state['number'] ?? null,
            'handoff' => Cache::store('file')->has('waha-test-handoff:'.$this->suffix()),
            'mail' => Cache::store('file')->get('waha-mail-status'),
            'manual_stop' => is_file(storage_path('app/private/waha/PAUSA')),
            'local' => app()->environment('local'),
        ];
    }
}
