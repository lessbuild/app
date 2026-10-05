<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\PlatformStatusDay;
use App\Models\PlatformStatusIncident;
use App\Models\PlatformStatusSubscriber;
use App\Notifications\PlatformStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * BuildPusher's own status over time. Every few minutes each part of the platform is checked and the day's tally
 * updated; a part that fails two checks in a row opens an incident, and the first check it passes resolves it, emailing
 * confirmed subscribers both times. The public status page reads 90 days of bars and the recent incidents from here.
 */
final class PlatformStatusHistory
{
    /**
     * How many days of bars the status page shows.
     *
     * @var int
     */
    public const DAYS = 90;

    /**
     * How many failed checks in a row open an incident, so one slow moment doesn't.
     *
     * @var int
     */
    private const FAILURES_TO_OPEN = 2;

    /**
     * Create a new PlatformStatusHistory instance.
     *
     * @param  PlatformStatus  $status  Checks each part of the platform.
     */
    public function __construct(private readonly PlatformStatus $status) {}

    /**
     * Check every part now, add the result to today's tally, and open or resolve incidents.
     *
     * @return void
     */
    public function record(): void
    {
        $snapshot = $this->status->fresh();
        $now = CarbonImmutable::now('UTC');
        foreach ($snapshot['components'] as $component) {
            $this->tally($now, $component['key'], $component['operational']);
            $this->follow($now, $component['key'], $component['name'], $component['operational']);
        }
    }

    /**
     * Each part's last 90 days, oldest first: "up" with no failed checks, "degraded" when some failed, "down" when at
     * least half did, "none" without checks; and its uptime over the days that were checked (null with none).
     *
     * @param  list<string>  $components  The parts' keys.
     * @return array<string, array{days: list<string>, uptime: float|null}>
     */
    public function days(array $components): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $from = $today->subDays(self::DAYS - 1);
        $rows = PlatformStatusDay::query()->whereIn('component', $components)->where('day', '>=', $from->toDateString())->get()
            ->groupBy('component');
        $history = [];
        foreach ($components as $component) {
            $byDay = ($rows[$component] ?? collect())->keyBy(fn (PlatformStatusDay $row): string => substr((string) $row->day, 0, 10));
            $days = [];
            $checks = 0;
            $failures = 0;
            for ($offset = self::DAYS - 1; $offset >= 0; $offset--) {
                $row = $byDay[$today->subDays($offset)->toDateString()] ?? null;
                if (! $row instanceof PlatformStatusDay || $row->checks === 0) {
                    $days[] = 'none';

                    continue;
                }
                $checks += $row->checks;
                $failures += $row->failures;
                $days[] = match (true) {
                    $row->failures === 0 => 'up',
                    $row->failures * 2 >= $row->checks => 'down',
                    default => 'degraded',
                };
            }
            $history[$component] = ['days' => $days, 'uptime' => $checks === 0 ? null : round(($checks - $failures) / $checks * 100, 2)];
        }

        return $history;
    }

    /**
     * Incidents still open or started in the last 30 days, newest first, at most ten.
     *
     * @return list<array{id: int, name: string, startedAt: string, resolvedAt: string|null, minutes: int|null}>
     */
    public function incidents(): array
    {
        return array_values(PlatformStatusIncident::query()
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('started_at', '>=', now()->subDays(30)))
            ->latest('started_at')->limit(10)->get()
            ->map(fn (PlatformStatusIncident $incident): array => [
                'id' => $incident->id,
                'name' => $incident->name,
                'startedAt' => $incident->started_at->toIso8601String(),
                'resolvedAt' => $incident->resolved_at?->toIso8601String(),
                'minutes' => $incident->resolved_at === null ? null : max(1, (int) round($incident->started_at->diffInMinutes($incident->resolved_at))),
            ])->all());
    }

    /**
     * Add one check to a part's tally for today.
     *
     * @param  CarbonImmutable  $now
     * @param  string  $component
     * @param  bool  $operational
     * @return void
     */
    private function tally(CarbonImmutable $now, string $component, bool $operational): void
    {
        DB::transaction(function () use ($now, $component, $operational): void {
            $row = PlatformStatusDay::query()->where('day', $now->toDateString())->where('component', $component)->lockForUpdate()->first();
            if ($row === null) {
                $row = new PlatformStatusDay;
                $row->forceFill(['day' => $now->toDateString(), 'component' => $component, 'checks' => 0, 'failures' => 0]);
            }
            $row->forceFill(['checks' => $row->checks + 1, 'failures' => $row->failures + ($operational ? 0 : 1)])->save();
        });
    }

    /**
     * Open an incident after enough failed checks in a row, or resolve the open one on the first passing check, and tell
     * subscribers either way.
     *
     * @param  CarbonImmutable  $now
     * @param  string  $component
     * @param  string  $name
     * @param  bool  $operational
     * @return void
     */
    private function follow(CarbonImmutable $now, string $component, string $name, bool $operational): void
    {
        $streak = 'platform:status:failures:'.$component;
        $open = PlatformStatusIncident::query()->where('component', $component)->whereNull('resolved_at')->first();
        if ($operational) {
            Cache::forget($streak);
            if ($open !== null) {
                $open->forceFill(['resolved_at' => $now])->save();
                $this->tell($open);
            }

            return;
        }
        $failures = (int) Cache::get($streak, 0) + 1;
        Cache::put($streak, $failures, now()->addDay());
        if ($open === null && $failures >= self::FAILURES_TO_OPEN) {
            $incident = new PlatformStatusIncident;
            $incident->forceFill(['component' => $component, 'name' => $name, 'started_at' => $now])->save();
            $this->tell($incident);
        }
    }

    /**
     * Email every confirmed subscriber that an incident opened or was resolved.
     *
     * @param  PlatformStatusIncident  $incident
     * @return void
     */
    private function tell(PlatformStatusIncident $incident): void
    {
        PlatformStatusSubscriber::query()->whereNotNull('verified_at')->each(function (PlatformStatusSubscriber $subscriber) use ($incident): void {
            Notification::route('mail', $subscriber->email)->notify(new PlatformStatusChanged($incident, $subscriber));
        });
    }
}
