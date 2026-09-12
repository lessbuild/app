<?php

namespace App\Services;

use App\Models\SignInEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignInHistoryQuery
{
    public function __construct(private readonly ClientMetadata $clients) {}

    /**
     * Build the request user's filtered sign-in history query.
     *
     * @param  array{method: ?string, date_from: ?string, date_to: ?string}  $filters  Validated history filters.
     * @return HasMany<SignInEvent, User> The user-scoped sign-in history query.
     */
    public function for(User $user, array $filters): HasMany
    {
        return $user->signIns()
            ->when($filters['method'], fn ($query, string $method) => $query
                ->where('method', $method))
            ->when($filters['date_from'], fn ($query, string $date) => $query
                ->whereDate('signed_in_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query
                ->whereDate('signed_in_at', '<=', $date));
    }

    /**
     * Calculate sign-in metrics from the same filtered, account-scoped query used by the history page.
     *
     * @param  array{method: ?string, date_from: ?string, date_to: ?string}  $filters  Validated history filters.
     * @return array{total: int, password: int, social: int, known_ips: int, latest_at: CarbonInterface|null}
     */
    public function metrics(User $user, array $filters): array
    {
        $socialMethods = array_values(array_diff(SignInEvent::METHODS, [SignInEvent::METHOD_PASSWORD]));
        $latest = $this->for($user, $filters)
            ->select(['id', 'signed_in_at'])
            ->orderByDesc('signed_in_at')
            ->orderByDesc('id')
            ->first();
        $knownIps = $this->for($user, $filters)
            ->select('ip_address')
            ->whereNotNull('ip_address')
            ->distinct()
            ->pluck('ip_address')
            ->map(fn (string $ip): ?string => $this->clients->normalizedIp($ip))
            ->filter()
            ->unique()
            ->count();

        return [
            'total' => $this->for($user, $filters)->count(),
            'password' => $this->for($user, $filters)
                ->where('method', SignInEvent::METHOD_PASSWORD)
                ->count(),
            'social' => $this->for($user, $filters)
                ->whereIn('method', $socialMethods)
                ->count(),
            'known_ips' => $knownIps,
            'latest_at' => $latest?->signed_in_at,
        ];
    }
}
