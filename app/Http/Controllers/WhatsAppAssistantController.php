<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppAssistantSettings;
use App\Services\WhatsAppMissingVehicleForm;
use App\Services\WhatsAppQuotePdf;
use App\Services\WhatsAppSessionConnection;
use App\Services\WhatsAppTechnicalAlert;
use App\Services\WhatsAppTestBot;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

class WhatsAppAssistantController extends Controller
{
    public function index(WhatsAppAssistantSettings $settings, WhatsAppSessionConnection $connection)
    {
        return Inertia::render('WhatsAppAssistant', ['previewPhotoEmailSent' => session('whatsapp_preview.photo_email_sent', false), 'previewChat' => session('whatsapp_preview.chat', []), 'rules' => $settings->get(), 'runtime' => $settings->runtime() + ['connection' => $connection->status()]]);
    }

    public function update(Request $request, WhatsAppAssistantSettings $settings)
    {
        $request->merge(['test_number' => preg_replace('/[\s+()-]/', '', (string) $request->input('test_number'))]);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'test_number' => ['required', 'regex:/^[1-9]\d{7,14}$/'],
            'fallback_language' => ['sometimes', Rule::in(['es', 'it', 'en'])],
            'reply_delay_seconds' => ['sometimes', 'integer', 'between:0,15'],
            'language' => ['required', Rule::in(['auto', 'it', 'es', 'en'])],
            'tone' => ['required', Rule::in(['diretto', 'cordiale', 'professionale'])],
            'max_words' => ['required', 'integer', 'between:15,120'],
            'max_questions' => ['required', 'integer', 'between:0,1'],
            'max_products' => ['required', 'integer', 'between:1,3'],
            'direct_offers' => ['required', 'boolean'], 'show_prices' => ['required', 'boolean'],
            'show_links' => ['required', 'boolean'], 'price_disclaimer' => ['required', 'boolean'],
            'custom_rules' => ['nullable', 'string', 'max:1500'],
            'handoff_keywords' => ['nullable', 'string', 'max:1000'], 'handoff_on_missing' => ['required', 'boolean'],
            'welcome_messages' => ['sometimes', 'array:it,es,en'],
            'welcome_messages.*' => ['required', 'string', 'max:500'],
            'standard_replies' => ['sometimes', 'array', 'max:50'],
            'standard_replies.*' => ['array:answer,answers,enabled,topic,title,questions,category'],
            'standard_replies.*.category' => ['sometimes', Rule::in(['general', 'product'])],
            'standard_replies.*.questions' => ['sometimes', 'array:it,es,en'],
            'standard_replies.*.questions.*' => ['nullable', 'string', 'max:1500'],
            'standard_replies.*.title' => ['nullable', 'string', 'max:100'],
            'standard_replies.*.topic' => ['sometimes', 'string', 'max:80'],
            'standard_replies.*.answer' => ['required_without:standard_replies.*.answers', 'string', 'max:1000'],
            'standard_replies.*.answers' => ['sometimes', 'array:it,es,en'],
            'standard_replies.*.answers.it' => ['required_with:standard_replies.*.answers', 'string', 'max:1000'],
            'standard_replies.*.answers.es' => ['nullable', 'string', 'max:1000'],
            'standard_replies.*.answers.en' => ['nullable', 'string', 'max:1000'],
            'standard_replies.*.enabled' => ['required', 'boolean'],
        ]);
        foreach (['custom_rules', 'handoff_keywords'] as $field) {
            $data[$field] = $data[$field] ?? '';
        }
        $settings->save($data);

        return back()->with('status', 'Regole WhatsApp salvate.');
    }

    public function status(WhatsAppAssistantSettings $settings, WhatsAppSessionConnection $connection)
    {
        return response()->json($settings->runtime() + ['connection' => $connection->status()]);
    }

    public function recoverUnanswered(WhatsAppAssistantSettings $settings)
    {
        $rules = $settings->get();
        $results = [];
        foreach (array_unique([$rules['test_number'], ...($rules['additional_test_numbers'] ?? [])]) as $number) {
            try {
                $bot = new WhatsAppTestBot;
                $bot->useNumber($number);
                $results[] = ['number' => $number] + $bot->recoverUnanswered();
            } catch (Throwable $e) {
                report($e);
                $results[] = ['number' => $number, 'state' => 'error', 'questions' => 0, 'sent' => 0];
            }
        }

        return response()->json(['results' => $results, 'sent' => array_sum(array_column($results, 'sent'))]);
    }

    public function restart(WhatsAppSessionConnection $connection)
    {
        if (! $connection->supported()) {
            return response()->json(['message' => 'Riavvio disponibile solo sul Mac con WAHA configurato.'], 422);
        }
        try {
            return response()->json(['connection' => $connection->restart()], 202);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            return response()->json(['message' => 'WAHA non raggiungibile o riavvio non riuscito. Verifica che Docker e il container WAHA siano attivi.'], 503);
        }
    }

    public function reset(WhatsAppAssistantSettings $settings)
    {
        $settings->reset();

        return response()->json(['reset' => true]);
    }

    public function quotePdf(string $id, WhatsAppQuotePdf $pdf)
    {
        $document = $pdf->document($id);

        return response()->download($pdf->path($id), $document['filename'], ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    public function resetPreview(Request $request)
    {
        app(\App\Services\WhatsAppPhotoReview::class)->discard($request->session()->get('whatsapp_preview.photos', []));
        $request->session()->forget('whatsapp_preview');

        return response()->json(['reset' => true]);
    }

    public function sendPreviewPhotoEmail(Request $request, WhatsAppAssistantSettings $settings)
    {
        $state = $request->session()->get('whatsapp_preview', []);
        if (! empty($state['photo_email_sent'])) return response()->json(['sent' => true]);
        if (empty($state['photos']) || ! collect($state['chat'] ?? [])->contains('mode', 'photo_review')) {
            return response()->json(['message' => 'Carica una foto e completa marca, modello e anno nella simulazione.'], 422);
        }
        try {
            $review = app(\App\Services\WhatsAppPhotoReview::class);
            $result = $review->step(collect($state['history'] ?? [])->where('role', 'user')->last()['content'] ?? '',
                $state['history'] ?? [], $state['vehicle'] ?? [], $state['quote_state'] ?? [],
                $settings->get()['test_number'], $state['photos'], 'it');
            if (empty($result['alert'])) return response()->json(['message' => 'Completa i dati dell’auto prima di inviare la prova.'], 422);
            app(WhatsAppTechnicalAlert::class)->send($result['alert'] + ['photo_test' => true]);
            $request->session()->put('whatsapp_preview.photo_email_sent', true);
            $request->session()->put('whatsapp_preview.photos', []);
            $review->discard($state['photos']);

            return response()->json(['sent' => true]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Invio email di prova non riuscito. Controlla la configurazione SMTP e riprova.'], 503);
        }
    }

    public function preview(Request $request, WhatsAppTestBot $bot, WhatsAppAssistantSettings $settings)
    {
        $data = $request->validate(['message' => ['required_without_all:photo,audio', 'nullable', 'string', 'max:3000'],
            'photo' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
            'audio' => ['nullable', 'file', 'mimetypes:audio/ogg,application/ogg,audio/opus,audio/mpeg,audio/wav,audio/x-wav,audio/mp4,audio/x-m4a,audio/webm,video/webm', 'max:12288']]);
        $data['message'] = (string) ($data['message'] ?? '');
        try {
            $state = $request->session()->get('whatsapp_preview', []);
            if (! $settings->get()['enabled']) {
                return response()->json(['message' => 'Risposte automatiche in pausa. Riattivale nelle regole per continuare la simulazione.'], 409);
            }
            if ($request->hasFile('audio')) {
                try {
                    $transcript = app(\App\Services\WhatsAppVoice::class)->upload($request->file('audio'));
                    $data['message'] = trim($data['message']."\n".$transcript);
                    if (mb_strlen($data['message']) > 3000) return response()->json(['message' => 'Testo e trascrizione superano 3000 caratteri. Usa un audio più breve.'], 422);
                } catch (Throwable $e) {
                    return response()->json(['message' => 'Non riesco a trascrivere l’audio. Usa un file chiaro di massimo 2 minuti e verifica che Whisper e FFmpeg siano disponibili sul Mac.'], 422);
                }
            }
            if (now()->timestamp - ($state['memory_at'] ?? now()->timestamp) >= 43200) {
                app(\App\Services\WhatsAppPhotoReview::class)->discard($state['photos'] ?? []);
                unset($state['history'], $state['vehicle'], $state['offer'], $state['form'], $state['technical'], $state['quote_state'], $state['photos']);
            }
            $waiting = ! empty($state['handoff_until']);
            $photos = $state['photos'] ?? [];
            if (! $waiting && $request->hasFile('photo')) $photos[] = app(\App\Services\WhatsAppPhotoReview::class)->upload($request->file('photo'));
            $history = $state['history'] ?? [];
            $needsWelcome = ! isset($state['last_activity']) || now()->timestamp - $state['last_activity'] >= 86400;
            $result = $waiting ? $bot->waitingAnswer($data['message'], $history, $state['vehicle'] ?? [], $state['quote_state'] ?? []) : $bot->conversationAnswer($data['message'], $history, $state['vehicle'] ?? [], $state['offer'] ?? null, $needsWelcome, $state['form'] ?? [], $settings->get()['test_number'], $state['technical'] ?? [], $state['quote_state'] ?? [], $photos);
            $bot->waitBeforeSend();
            if (! $settings->get()['enabled']) {
                return response()->json(['message' => 'Risposte messe in pausa durante l’attesa. Nessuna risposta inviata.'], 409);
            }
            if ($result['mode'] === 'missing_submitted') {
                try {
                    app(WhatsAppMissingVehicleForm::class)->submit($result['form']['data']);
                } catch (Throwable $e) {
                    report($e);

                    return response()->json(['message' => 'Invio email della richiesta radio non riuscito. Controlla la configurazione email e riprova.'], 503);
                }
            }
            if (isset($result['alert']) && $result['mode'] !== 'photo_review') {
                try {
                    app(WhatsAppTechnicalAlert::class)->send($result['alert']);
                } catch (Throwable $e) {
                    report($e);

                    return response()->json(['message' => 'Invio email all’operatore non riuscito. Riprova.'], 503);
                }
            }
            $chat = array_slice([...($state['chat'] ?? []), ['question' => ($request->hasFile('audio') ? "[Audio trascritto]\n" : '').($data['message'] !== '' ? $data['message'] : ($request->hasFile('photo') ? '[Foto allegata]' : '')),
                'text' => $result['text'], 'mode' => $result['mode'],
                'matches' => $result['context']['matching_specific_screens'] + ($result['context']['matching_universal_screens'] ?? 0), 'document' => $result['document'] ?? null]], -30);
            $request->session()->put('whatsapp_preview', [
                'history' => array_slice([...$history, ['role' => 'user', 'content' => $data['message']], ['role' => 'assistant', 'content' => $result['text']]], -6),
                'vehicle' => $result['context']['vehicle'],
                'offer' => in_array($result['mode'], ['offer', 'quote'], true) ? $result['signature'] : ($state['offer'] ?? null),
                'quote_state' => $result['quote_state'] ?? ($state['quote_state'] ?? []),
                'technical' => $result['technical'] ?? ($state['technical'] ?? []),
                'photos' => $result['photos'] ?? $photos,
                'photo_email_sent' => $state['photo_email_sent'] ?? false,
                'form' => $result['form'] ?? ($state['form'] ?? []),
                'chat' => $chat, 'last_activity' => now()->timestamp, 'memory_at' => now()->timestamp,
                'handoff_until' => in_array($result['mode'], ['handoff', 'technical_handoff', 'photo_review'], true) ? now()->timestamp + 43200
                    : ($state['handoff_until'] ?? null),
            ]);

            return response()->json([
                'document' => $result['document'] ?? null, 'chat' => $chat, 'text' => $result['text'], 'mode' => $result['mode'],
                'vehicle' => $result['context']['vehicle'],
                'matches' => $result['context']['matching_specific_screens'] + ($result['context']['matching_universal_screens'] ?? 0),
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Anteprima non disponibile. Controlla la configurazione AI e riprova.'], 503);
        }
    }
}
