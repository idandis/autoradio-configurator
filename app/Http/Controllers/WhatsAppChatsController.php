<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppChat;
use App\Services\WhatsAppChatControl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class WhatsAppChatsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:30']]);

        $rules = app(\App\Services\WhatsAppAssistantSettings::class)->get();
        $allowed = [$rules['test_number'], ...($rules['additional_test_numbers'] ?? [])];

        return response()->json(WhatsAppChat::where('session', config('waha.session'))
            ->when($data['q'] ?? null, fn ($q, $phone) => $q->where('phone', 'like', '%'.preg_replace('/\D/', '', $phone).'%'))
            ->orderByDesc('paused')->orderByDesc('last_activity_at')->orderByDesc('id')->paginate(25)
            ->through(fn ($chat) => [...$chat->toArray(), 'authorized' => in_array($chat->phone, $allowed, true)]));
    }

    public function labels(WhatsAppChatControl $control)
    {
        try {
            return response()->json(['labels' => $control->labels()]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Etichette non disponibili. Verifica il collegamento WAHA e le etichette WhatsApp Business.'], 503);
        }
    }

    public function sync(Request $request, WhatsAppChatControl $control)
    {
        $data = $request->validate(['offset' => ['required', 'integer', 'min:0', 'max:100000'],
            'label_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/']]);
        try {
            $label = $data['label_id'] ?? null;
            $count = $control->syncPage($data['offset'], $label);

            return response()->json(['next_offset' => $label === null && $count === 100 ? $data['offset'] + 100 : null] + $control->importStats);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Sincronizzazione non riuscita. Verifica che WAHA locale sia collegato.'], 503);
        }
    }

    public function syncHistory(WhatsAppChat $chat, \App\Services\WhatsAppHistorySync $history)
    {
        abort_unless($chat->session === config('waha.session'), 404);
        try {
            return response()->json($history->sync($chat));
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Recupero non riuscito. Verifica Docker e WAHA sul Mac, poi riprova.'], 503);
        }
    }

    public function manualRecontact(Request $request, WhatsAppChat $chat, \App\Services\WhatsAppRecontact $recontact)
    {
        abort_unless($chat->session === config('waha.session'), 404);
        $data = $request->validate(['text' => ['required', 'string', 'max:6000'], 'language' => ['required', 'string', 'max:60'],
            'instructions' => ['nullable', 'string', 'max:2000']]);
        try {
            return response()->json($recontact->manual($chat, $data));
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Riscrittura non riuscita. Il tuo testo è conservato: riprova.'], 503);
        }
    }

    public function rewriteRecontact(Request $request, WhatsAppChat $chat, \App\Services\WhatsAppRecontact $recontact)
    {
        abort_unless($chat->session === config('waha.session'), 404);
        $data = $request->validate(['text' => ['required', 'string', 'max:6000'], 'instructions' => ['required', 'string', 'max:2000']]);
        try {
            return response()->json($recontact->draft($chat, $data));
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Riscrittura non riuscita. La bozza precedente è conservata: riprova.'], 503);
        }
    }

    public function recontact(WhatsAppChat $chat, \App\Services\WhatsAppRecontact $recontact)
    {
        abort_unless($chat->session === config('waha.session'), 404);
        try {
            return response()->json($recontact->draft($chat));
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Bozza non disponibile. Verifica WAHA e il servizio AI, poi riprova.'], 503);
        }
    }

    public function saveLesson(Request $request, WhatsAppChat $chat, \App\Services\WhatsAppLessons $lessons)
    {
        abort_unless($chat->session === config('waha.session'), 404);
        $data = $request->validate([
            'scope' => ['required', \Illuminate\Validation\Rule::in(['general', 'product', 'client'])],
            'instruction' => ['required', 'string', 'max:1500'], 'text' => ['required', 'string', 'max:6000'],
            'product' => ['nullable', 'string', 'max:255'], 'question' => ['nullable', 'string', 'max:500'],
            'locale' => ['required', 'regex:/^[a-z]{2}$/'],
            'manual' => ['sometimes', 'boolean'],
        ]);
        $cache = Cache::store('file');
        $key = 'waha-recontact:'.hash('sha256', $chat->session.':'.$chat->phone);
        $memory = $cache->get($key);
        $products = ($data['manual'] ?? false) ? [] : ($memory['result']['products'] ?? []);
        abort_if($data['scope'] === 'product' && ! in_array($data['product'] ?? '', $products, true), 422, 'Scegli un prodotto presente nella conversazione.');
        DB::table('whatsapp_lessons')->insert([
            'session' => $chat->session, 'scope' => $data['scope'],
            'target' => match ($data['scope']) { 'client' => $chat->phone, 'product' => $data['product'], default => '' },
            'instruction' => $data['instruction'], 'locale' => $data['locale'], 'question' => $data['question'] ?? null,
            'answer' => filled($data['question'] ?? null) ? $data['text'] : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows = $lessons->applicable($chat->phone, $products);
        if ($memory && ! ($data['manual'] ?? false)) {
            $memory['result']['text'] = $data['text'];
            $memory['result']['lessons'] = $rows;
            $memory['version'] = hash('sha256', json_encode([$rows, $chat->import_label_name]));
            $cache->forever($key, $memory);
        }

        return response()->json(['lessons' => $rows], 201);
    }

    public function deleteLesson(WhatsAppChat $chat, int $lesson, \App\Services\WhatsAppLessons $lessons)
    {
        abort_unless($chat->session === config('waha.session'), 404);
        $key = 'waha-recontact:'.hash('sha256', $chat->session.':'.$chat->phone);
        $products = Cache::store('file')->get($key)['result']['products'] ?? [];
        abort_unless(collect($lessons->applicable($chat->phone, $products))->contains('id', $lesson), 404);
        DB::table('whatsapp_lessons')->where('session', $chat->session)->where('id', $lesson)->delete();

        return response()->json(['lessons' => $lessons->applicable($chat->phone, $products)]);
    }

    public function destroy(WhatsAppChat $chat)
    {
        abort_unless($chat->session === config('waha.session'), 404);
        DB::transaction(function () use ($chat) {
            WhatsAppChat::whereKey($chat->id)->increment('revision');
            $chat->delete();
        });

        return response()->noContent();
    }

    public function destroyAll()
    {
        DB::transaction(function () {
            $query = WhatsAppChat::where('session', config('waha.session'));
            $query->increment('revision');
            $query->delete();
        });

        return response()->noContent();
    }

    public function update(Request $request, WhatsAppChat $chat, WhatsAppChatControl $control)
    {
        abort_unless($chat->session === config('waha.session'), 404);
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        if ($data['paused']) $control->pause($chat, 'dashboard');
        else $control->resume($chat);

        return response()->json($chat->fresh());
    }
}
