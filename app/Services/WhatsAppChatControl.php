<?php

namespace App\Services;

use App\Models\WhatsAppChat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppChatControl
{
    public function contact(string $phone): WhatsAppChat
    {
        $chat = WhatsAppChat::withTrashed()->firstOrCreate(['session' => config('waha.session'), 'phone' => preg_replace('/\D/', '', $phone)]);
        $suffix = hash('sha256', $chat->session.$chat->phone.'@c.us');
        if (! $chat->paused && Cache::store('file')->has('waha-test-handoff:'.$suffix)) {
            $this->pause($chat, 'handoff');
            $chat->refresh();
        }

        return $chat->refresh();
    }

    private function api(): \Illuminate\Http\Client\PendingRequest
    {
        if (! app()->environment('local') || ! in_array(config('waha.url'), ['http://127.0.0.1:3000', 'http://localhost:3000'], true) || ! config('waha.key')) {
            throw new RuntimeException('Sincronizzazione disponibile solo con WAHA locale configurato.');
        }

        return Http::baseUrl(config('waha.url'))->withHeaders(['X-Api-Key' => config('waha.key')])->connectTimeout(5)->timeout(30);
    }

    public function labels(): array
    {
        $session = rawurlencode(config('waha.session'));
        $rows = $this->api()->get("/api/{$session}/labels")->throw()->json();
        if (! is_array($rows) || ! array_is_list($rows)) throw new RuntimeException('Elenco etichette WAHA non valido.');

        return collect($rows)->filter(fn ($r) => is_array($r) && isset($r['id']) && is_string($r['name'] ?? null))
            ->map(fn ($r) => ['id' => (string) $r['id'], 'name' => $r['name']])->values()->all();
    }

    public function syncPage(int $offset, ?string $labelId = null, bool $existingOnly = false): int
    {
        $api = $this->api();
        if ($labelId !== null && $offset > 0) return 0;
        $label = $labelId !== null ? collect($this->labels())->firstWhere('id', $labelId) : null;
        if ($labelId !== null && ! $label) throw new RuntimeException('Etichetta WhatsApp non trovata.');
        $session = rawurlencode(config('waha.session'));
        $rows = $labelId !== null
            ? $api->get("/api/{$session}/labels/".rawurlencode($labelId).'/chats')->throw()->json()
            : $api->get("/api/{$session}/chats/overview", ['limit' => 100, 'offset' => $offset])->throw()->json();
        if (! is_array($rows) || ! array_is_list($rows)) throw new RuntimeException('Elenco chat WAHA non valido.');
        $mapped = [];
        foreach ($rows as $row) {
            $id = is_string($row) ? $row : ($row['id'] ?? $row['chatId'] ?? '');
            if (is_array($id)) $id = $id['_serialized'] ?? '';
            if (is_string($id) && preg_match('/^\d+@lid$/', $id)) {
                if (! array_key_exists($id, $mapped)) {
                    $mapping = $api->get("/api/{$session}/lids/".rawurlencode($id))->throw()->json();
                    $mapped[$id] = ($mapping['lid'] ?? null) === $id ? ($mapping['pn'] ?? null) : null;
                }
                $id = $mapped[$id];
            }
            if (! is_string($id) || ! preg_match('/^([1-9]\d{7,14})@c\.us$/', $id, $m)) continue;
            $chat = $existingOnly
                ? WhatsAppChat::where('session', config('waha.session'))->where('phone', $m[1])->first()
                : $this->contact($m[1]);
            if (! $chat) continue;
            if ($chat->trashed()) $chat->restore();
            if ($label !== null) $chat->update(['import_label_id' => $label['id'], 'import_label_name' => $label['name']]);
            if (is_array($row) && is_array($row['lastMessage'] ?? null)) $this->observe($chat, [$row['lastMessage']]);
        }

        return count($rows);
    }

    public function pause(WhatsAppChat $chat, string $reason): void
    {
        WhatsAppChat::withTrashed()->whereKey($chat->id)->update(['paused' => true, 'pause_reason' => $reason,
            'paused_at' => now(), 'revision' => DB::raw('revision + 1')]);
    }

    public function resume(WhatsAppChat $chat): void
    {
        WhatsAppChat::withTrashed()->whereKey($chat->id)->update(['paused' => false, 'pause_reason' => null,
            'paused_at' => null, 'resumed_at' => now(), 'revision' => DB::raw('revision + 1')]);
        $suffix = hash('sha256', $chat->session.$chat->phone.'@c.us');
        app(WhatsAppPhotoReview::class)->discard(Cache::store('file')->get('waha-test-photos:'.$suffix, []));
        foreach (['handoff', 'technical', 'form', 'photos'] as $kind) Cache::store('file')->forget('waha-test-'.$kind.':'.$suffix);
        Cache::store('file')->put('waha-test-revision:'.$suffix, bin2hex(random_bytes(12)), now()->addDay());
    }

    public function receipt(WhatsAppChat $chat, string $id, string $kind): bool
    {
        return (bool) DB::table('whatsapp_chat_receipts')->insertOrIgnore([
            'whatsapp_chat_id' => $chat->id, 'message_hash' => hash('sha256', $id), 'kind' => $kind,
        ]);
    }

    public function observe(WhatsAppChat $chat, array $messages): void
    {
        foreach ($messages as $message) {
            $at = (int) ($message['timestamp'] ?? 0);
            if ($at > ($chat->last_activity_at?->timestamp ?? 0) && $at <= time() + 60) {
                $chat->last_activity_at = \Illuminate\Support\Carbon::createFromTimestamp($at);
            }
            $id = $message['id'] ?? null;
            if (is_array($id)) $id = $id['_serialized'] ?? null;
            if (($message['fromMe'] ?? false) !== true || ! is_string($id)) continue;
            if ($this->receipt($chat, $id, 'manual') && $at >= $chat->created_at->timestamp
                && $at > ($chat->resumed_at?->timestamp ?? 0)) {
                $this->pause($chat, 'operator_message');
            }
        }
        if ($chat->isDirty('last_activity_at')) $chat->save();
    }
}
