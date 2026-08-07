<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TwoFactorChallenge
{
    public function issue(User $user): string
    {
        $id = Str::random(40);
        $ttl = max(60, (int) config('pbx3_auth.challenge_ttl', 300));
        Cache::put($this->key($id), [
            'user_id' => $user->id,
            'attempts' => 0,
        ], $ttl);

        return $id;
    }

    /**
     * @return array{user_id: int|string, attempts: int}|null
     */
    public function get(string $challengeId): ?array
    {
        $payload = Cache::get($this->key($challengeId));
        if (! is_array($payload) || ! isset($payload['user_id'])) {
            return null;
        }

        return [
            'user_id' => $payload['user_id'],
            'attempts' => (int) ($payload['attempts'] ?? 0),
        ];
    }

    public function bumpAttempts(string $challengeId): int
    {
        $payload = $this->get($challengeId);
        if ($payload === null) {
            return 0;
        }
        $payload['attempts']++;
        $ttl = max(60, (int) config('pbx3_auth.challenge_ttl', 300));
        Cache::put($this->key($challengeId), $payload, $ttl);

        return $payload['attempts'];
    }

    public function forget(string $challengeId): void
    {
        Cache::forget($this->key($challengeId));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('pbx3_auth.challenge_max_attempts', 5));
    }

    private function key(string $challengeId): string
    {
        return '2fa_challenge:'.$challengeId;
    }
}
