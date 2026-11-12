<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AutogenesiAliExpressAuth
{
    private function path(int $user): string
    {
        return 'autogenesi/aliexpress/'.hash('sha256', config('services.aliexpress.app_key').':'.$user).'.enc';
    }

    public function status(int $user): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($this->path($user))) {
            return ['connected' => false, 'expires_at' => null];
        }
        try {
            $data = json_decode(Crypt::decryptString($disk->get($this->path($user))), true, 512, JSON_THROW_ON_ERROR);
            if (($data['expires_at'] ?? 0) <= time() + 600 && empty($data['invalidated']) && $this->refreshValid($data)) {
                $data = $this->refresh($user);
            }
            return ['connected' => ($data['expires_at'] ?? 0) > time(), 'expires_at' => $data['expires_at'] ?? null];
        } catch (Throwable) {
            return ['connected' => ($data['expires_at'] ?? 0) > time(), 'expires_at' => $data['expires_at'] ?? null];
        }
    }

    public function exchangePending(int $user): void
    {
        abort_unless(app()->environment('local'), 403);
        if (! config('services.aliexpress.app_key') || ! config('services.aliexpress.app_secret')) {
            throw new RuntimeException('Configura App Key e App Secret nel file .env locale.');
        }
        $lock = Cache::lock('autogenesi.aliexpress.exchange.'.$user, 60);
        if (! $lock->get()) {
            throw new RuntimeException('Autorizzazione già in elaborazione. Attendi qualche secondo.');
        }
        try {
            $encrypted = Cache::pull('autogenesi.aliexpress.code.'.$user);
            if (! $encrypted) {
                throw new RuntimeException('Codice assente o scaduto. Premi Autorizza AliExpress.');
            }
            $this->exchange($user, Crypt::decryptString($encrypted));
        } finally {
            $lock->release();
        }
    }

    public function accessToken(int $user): string
    {
        abort_unless(app()->environment('local'), 403);
        if (! $this->status($user)['connected']) {
            throw new RuntimeException('Autorizzazione AliExpress scaduta o assente. Ripeti l’autorizzazione.');
        }
        $data = json_decode(Crypt::decryptString(Storage::disk('local')->get($this->path($user))), true, 512, JSON_THROW_ON_ERROR);
        return $data['access_token'];
    }

    public function invalidate(int $user, ?string $expectedToken = null): void
    {
        abort_unless(app()->environment('local'), 403);
        $disk = Storage::disk('local');
        if (!$disk->exists($this->path($user))) return;
        $data = json_decode(Crypt::decryptString($disk->get($this->path($user))), true, 512, JSON_THROW_ON_ERROR);
        if ($expectedToken !== null && !hash_equals($data['access_token'], $expectedToken)) return;
        $data['expires_at'] = 0;
        $data['invalidated'] = true;
        $disk->put($this->path($user), Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    private function refreshValid(array $data): bool
    {
        $expiry = $data['refresh_expires_at'] ?? (strtotime($data['received_at'] ?? '') + (int) ($data['refresh_expires_in'] ?? 0));
        return is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '' && $expiry > time();
    }

    private function refresh(int $user): array
    {
        abort_unless(app()->environment('local'), 403);
        return Cache::lock('autogenesi.aliexpress.refresh.'.$user, 40)->block(5, function () use ($user) {
            $disk = Storage::disk('local');
            $data = json_decode(Crypt::decryptString($disk->get($this->path($user))), true, 512, JSON_THROW_ON_ERROR);
            if (($data['expires_at'] ?? 0) > time() + 600 || empty($data['refresh_token']) || ! $this->refreshValid($data)) return $data;
            $params = [
                'app_key' => (string) config('services.aliexpress.app_key'),
                'refresh_token' => $data['refresh_token'],
                'sign_method' => 'sha256',
                'timestamp' => (string) (int) floor(microtime(true) * 1000),
            ];
            ksort($params, SORT_STRING);
            $base = '/auth/token/refresh';
            foreach ($params as $key => $value) $base .= $key.$value;
            $params['sign'] = strtoupper(hash_hmac('sha256', $base, config('services.aliexpress.app_secret')));
            $response = Http::asForm()->acceptJson()->connectTimeout(10)->timeout(30)
                ->post('https://api-sg.aliexpress.com/rest/auth/token/refresh', $params);
            $fresh = $response->json();
            if (! $response->successful() || ! is_array($fresh) || ! is_string($fresh['access_token'] ?? null)
                || $fresh['access_token'] === '' || ! is_numeric($fresh['expires_in'] ?? null) || $fresh['expires_in'] <= 0) {
                throw new RuntimeException('Rinnovo AliExpress non riuscito.');
            }
            $oldExpiry = $data['refresh_expires_at'] ?? (strtotime($data['received_at'] ?? '') + (int) ($data['refresh_expires_in'] ?? 0));
            $fresh['refresh_token'] = $fresh['refresh_token'] ?? $data['refresh_token'];
            $fresh['refresh_expires_at'] = min($oldExpiry, time() + (int) ($fresh['refresh_expires_in'] ?? 0));
            $fresh['expires_at'] = time() + (int) $fresh['expires_in'];
            $fresh['received_at'] = now()->toIso8601String();
            unset($fresh['code']);
            if (! $disk->put($this->path($user), Crypt::encryptString(json_encode($fresh, JSON_THROW_ON_ERROR))))
                throw new RuntimeException('Impossibile salvare il token AliExpress rinnovato.');
            @chmod($disk->path($this->path($user)), 0600);
            return $fresh;
        });
    }

    private function exchange(int $user, string $code): void
    {
        $params = [
            'app_key' => (string) config('services.aliexpress.app_key'),
            'code' => $code,
            'sign_method' => 'sha256',
            'timestamp' => (string) (int) floor(microtime(true) * 1000),
        ];
        ksort($params, SORT_STRING);
        $base = '/auth/token/create';
        foreach ($params as $key => $value) {
            $base .= $key.$value;
        }
        $params['sign'] = strtoupper(hash_hmac('sha256', $base, config('services.aliexpress.app_secret')));
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(10)->timeout(30)
                ->post('https://api-sg.aliexpress.com/rest/auth/token/create', $params);
        } catch (Throwable) {
            throw new RuntimeException('Connessione AliExpress non riuscita. Ripeti l’autorizzazione.');
        }
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ! is_string($data['access_token'] ?? null)
            || $data['access_token'] === '' || ! is_numeric($data['expires_in'] ?? null) || $data['expires_in'] <= 0) {
            $error = $data['code'] ?? $response->status();
            $error = is_scalar($error) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', (string) $error) ? (string) $error : 'risposta_non_valida';
            throw new RuntimeException('AliExpress non ha rilasciato il token ('.$error.'). Ripeti l’autorizzazione.');
        }
        $data['expires_at'] = time() + (int) $data['expires_in'];
        $data['refresh_expires_at'] = time() + (int) ($data['refresh_expires_in'] ?? 0);
        $data['received_at'] = now()->toIso8601String();
        unset($data['code']);
        if (! Storage::disk('local')->put($this->path($user), Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('Impossibile salvare il token sul Mac. Ripeti l’autorizzazione.');
        }
        @chmod(Storage::disk('local')->path($this->path($user)), 0600);
    }
}
