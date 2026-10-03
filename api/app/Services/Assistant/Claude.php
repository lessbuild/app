<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Calls Anthropic's Messages API with the platform's key, for the in-app assistant. */
class Claude
{
    /**
     * Determine whether a key is configured.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * Send one Messages API request and return the response.
     *
     * @param  string  $system
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array{content: list<array<string, mixed>>, stop_reason: string|null}
     */
    public function messages(string $system, array $messages, array $tools): array
    {
        $response = Http::withHeaders(['x-api-key' => (string) config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(120)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => (string) config('services.anthropic.model'), 'max_tokens' => 2048,
                'system' => $system, 'messages' => $messages, 'tools' => $tools,
            ]);
        if (! $response->successful()) {
            throw new RuntimeException($response->status() === 429 || $response->status() === 529
                ? __('The assistant is busy. Try again in a minute.')
                : __('The assistant couldn’t answer (HTTP :status).', ['status' => $response->status()]));
        }
        $content = $response->json('content');

        return ['content' => is_array($content) ? array_values(array_filter($content, is_array(...))) : [], 'stop_reason' => is_string($response->json('stop_reason')) ? $response->json('stop_reason') : null];
    }
}
