<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppVoice
{
    public function upload(UploadedFile $file): string
    {
        if (! app()->environment('local') || ! config('waha.voice_enabled')) throw new RuntimeException('Trascrizione locale disabilitata.');
        if ($file->getSize() > 12 * 1024 * 1024) throw new RuntimeException('Vocale troppo grande.');
        $disk = Storage::disk('local');
        $path = $disk->putFileAs('waha/voice', $file, Str::uuid().'.audio');
        if (! $path) throw new RuntimeException('Salvataggio vocale non riuscito.');
        try {
            return $this->transcribe($disk->path($path));
        } finally {
            $disk->delete($path);
        }
    }

    public function isVoice(array $message): bool
    {
        if (($message['hasMedia'] ?? false) !== true) return false;
        $mime = strtolower(explode(';', $message['media']['mimetype'] ?? $message['_data']['mimetype'] ?? '')[0]);

        return str_starts_with($mime, 'audio/') || in_array($message['_data']['type'] ?? $message['type'] ?? '', ['ptt', 'audio'], true);
    }

    public function receive(array $message, string $chat): string
    {
        if (! app()->environment('local') || ! config('waha.voice_enabled')) throw new RuntimeException('Trascrizione locale disabilitata.');
        $base = parse_url(config('waha.url'));
        if (! in_array($base['host'] ?? '', ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('WAHA deve essere locale.');
        $api = Http::baseUrl(config('waha.url'))->withHeaders(['X-Api-Key' => config('waha.key')])->connectTimeout(5)->timeout(20)->withoutRedirecting();
        $full = $api->get('/api/'.rawurlencode(config('waha.session')).'/chats/'.rawurlencode($chat).'/messages/'.rawurlencode($message['id']), ['downloadMedia' => 'true'])->throw()->json();
        if (! is_array($full) || ($full['id'] ?? null) !== $message['id'] || ! $this->isVoice($full)
            || ($full['fromMe'] ?? null) !== false || ($full['from'] ?? null) !== $message['from']) throw new RuntimeException('Vocale WhatsApp non verificabile.');
        $url = parse_url($full['media']['url'] ?? '');
        $path = $url['path'] ?? '';
        if (($url['host'] ?? '') !== $base['host'] || ($url['scheme'] ?? '') !== ($base['scheme'] ?? '')
            || ($url['port'] ?? 80) !== ($base['port'] ?? 80) || isset($url['user']) || isset($url['pass'])
            || ! str_starts_with($path, '/api/files/') || str_contains(rawurldecode($path), '..')) throw new RuntimeException('Percorso vocale WhatsApp non valido.');
        $disk = Storage::disk('local');
        $file = 'waha/voice/'.Str::uuid().'.audio';
        $disk->makeDirectory('waha/voice');
        try {
            $response = $api->withOptions(['sink' => $disk->path($file), 'progress' => function ($total, $size) {
                if (max($total, $size) > 12 * 1024 * 1024) throw new RuntimeException('Vocale troppo grande.');
            }])->get($path)->throw();
            if (! $response->successful() || ! $disk->exists($file) || $disk->size($file) > 12 * 1024 * 1024) throw new RuntimeException('Download vocale non riuscito.');

            return $this->transcribe($disk->path($file));
        } finally {
            $disk->delete($file);
        }
    }

    public function transcribe(string $file): string
    {
        $python = config('waha.voice_python');
        $model = config('waha.voice_model');
        if (! is_executable($python) || ! is_file($model)) throw new RuntimeException('Whisper locale non configurato.');
        $result = Process::env(['PATH' => '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin'])->timeout(90)
            ->run([$python, base_path('scripts/transcribe-whatsapp.py'), $file, $model])->throw();
        $data = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 3000) throw new RuntimeException('Vocale non comprensibile.');

        return $text;
    }
}
