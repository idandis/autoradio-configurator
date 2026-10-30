<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppChat;
use App\Services\WhatsAppChatControl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

            return response()->json(['next_offset' => $label === null && $count === 100 ? $data['offset'] + 100 : null]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Sincronizzazione non riuscita. Verifica che WAHA locale sia collegato.'], 503);
        }
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
