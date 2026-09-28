<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Enums\AccountRole;
use App\Models\Membership;
use App\Models\ServerAlertRule;
use App\Models\ServerMetric;
use App\Notifications\ServerAlertChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/** Checks a new server reading against the account's alert rules and tells owners and admins when one trips or recovers. */
class ServerAlerts
{
    /**
     * Checks a new reading against the account's enabled rules for the server (and for every server). A rule trips after
     * its consecutive breaches, outside its cooldown, and recovers on the first reading back in range; owners and admins
     * are told about both.
     *
     * @param  ServerMetric  $metric
     * @return void
     */
    public function evaluate(ServerMetric $metric): void
    {
        $server = $metric->server;
        $now = CarbonImmutable::now('UTC');
        $rules = ServerAlertRule::query()->where('account_id', $server->account_id)->where('is_enabled', true)
            ->where(fn ($query) => $query->whereNull('server_id')->orWhere('server_id', $server->id))->get();
        foreach ($rules as $rule) {
            $value = $metric->getAttribute($rule->metric);
            if (! is_numeric($value)) {
                continue;
            }
            $breached = $rule->operator === 'lte' ? (float) $value <= $rule->threshold : (float) $value >= $rule->threshold;
            $count = $breached ? min(255, $rule->breach_count + 1) : 0;
            $trip = $breached && $count >= $rule->consecutive_breaches && ! $rule->is_alerting
                && ($rule->last_triggered_at === null || $rule->last_triggered_at->lessThanOrEqualTo($now->subMinutes($rule->cooldown_minutes)));
            $recover = ! $breached && $rule->is_alerting;
            $rule->forceFill([
                'breach_count' => $count, 'is_alerting' => $trip || ($rule->is_alerting && ! $recover),
                'last_evaluated_at' => $now, 'last_triggered_at' => $trip ? $now : $rule->last_triggered_at,
            ])->save();
            if ($trip || $recover) {
                $recipients = Membership::query()->where('account_id', $server->account_id)->whereIn('role', [AccountRole::Owner, AccountRole::Admin])->with('user')->get()->pluck('user');
                Notification::send($recipients, new ServerAlertChanged($rule, $server, (float) $value, $trip));
            }
        }
    }
}
