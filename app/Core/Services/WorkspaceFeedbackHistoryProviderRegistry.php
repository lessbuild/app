<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceFeedbackHistoryProvider;

final class WorkspaceFeedbackHistoryProviderRegistry
{
    /** @var array<string, WorkspaceFeedbackHistoryProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceFeedbackHistoryProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceFeedbackHistoryProvider
    {
        return $this->providers[$product] ?? null;
    }
}
