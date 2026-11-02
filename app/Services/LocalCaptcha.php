<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LocalCaptcha
{
    private const SESSION_KEY = 'auth_captcha';

    private const LIFETIME_SECONDS = 300;

    private const MINIMUM_AGE_SECONDS = 2;

    /**
     * @return array{challenge: string, question: string}
     */
    public function issue(Request $request): array
    {
        $left = random_int(2, 9);
        $right = random_int(2, 9);
        $operation = random_int(0, 1) === 0 ? '+' : '-';

        if ($operation === '-' && $right > $left) {
            [$left, $right] = [$right, $left];
        }

        $answer = $operation === '+' ? $left + $right : $left - $right;
        $challenge = Str::random(40);
        $now = now()->timestamp;

        $request->session()->put(self::SESSION_KEY, [
            'challenge_hash' => hash('sha256', $challenge),
            'answer_hash' => $this->answerHash($request, $challenge, $answer),
            'binding_hash' => $this->bindingHash($request),
            'issued_at' => $now,
            'expires_at' => $now + self::LIFETIME_SECONDS,
        ]);

        return [
            'challenge' => $challenge,
            'question' => "Решите пример: {$left} {$operation} {$right} = ?",
        ];
    }

    public function verify(Request $request, mixed $challenge, mixed $answer): bool
    {
        $stored = $request->session()->pull(self::SESSION_KEY);

        if (is_array($stored) && is_string($stored['challenge_hash'] ?? null)) {
            $firstAttempt = Cache::add(
                'auth-captcha:consumed:'.$stored['challenge_hash'],
                true,
                self::LIFETIME_SECONDS,
            );
            if (! $firstAttempt) {
                return false;
            }
        }

        if (! is_array($stored)
            || ! is_string($challenge)
            || ! is_string($answer)
            || ! ctype_digit($answer)
            || ! is_string($stored['challenge_hash'] ?? null)
            || ! is_string($stored['answer_hash'] ?? null)
            || ! is_string($stored['binding_hash'] ?? null)
            || ! is_int($stored['issued_at'] ?? null)
            || ! is_int($stored['expires_at'] ?? null)) {
            return false;
        }

        $now = now()->timestamp;
        if ($now < $stored['issued_at'] + self::MINIMUM_AGE_SECONDS
            || $now > $stored['expires_at']
            || ! hash_equals($stored['challenge_hash'], hash('sha256', $challenge))
            || ! hash_equals($stored['binding_hash'], $this->bindingHash($request))) {
            return false;
        }

        return hash_equals(
            $stored['answer_hash'],
            $this->answerHash($request, $challenge, (int) $answer),
        );
    }

    private function answerHash(Request $request, string $challenge, int $answer): string
    {
        return hash_hmac(
            'sha256',
            $challenge.'|'.$answer.'|'.$this->bindingHash($request),
            (string) config('app.key'),
        );
    }

    private function bindingHash(Request $request): string
    {
        return hash('sha256', $request->session()->token());
    }
}
