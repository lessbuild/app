<?php

declare(strict_types=1);

namespace App\Data\Monitoring;

use App\Enums\AlertDestinationType;
use App\Models\OnCallSchedule;
use App\Models\User;
use App\Queries\Monitoring\AlertDestinationsQuery;
use App\Services\Monitoring\TwilioAlerts;

final readonly class AlertDestinationOptions
{
    /**
     * Create a new AlertDestinationOptions instance.
     *
     * The choices on an alert destination's form.
     *
     * @param  list<array{value: string, label: string, followsPerson: bool, isPhone: bool, usesUrl: bool}>  $types  The kinds of destination (texts and calls only when Twilio is set up).
     * @param  list<array{value: string, label: string}>  $schedules  On-call schedules, as `schedule:<id>`.
     * @param  list<array{value: string, label: string}>  $members  Verified members who can receive alerts.
     * @param  bool  $phones  Whether texts and calls can be sent.
     * @param  int  $dailyPhoneLimit  Texts and calls a number may get a day.
     */
    public function __construct(
        public array $types,
        public array $schedules,
        public array $members,
        public bool $phones,
        public int $dailyPhoneLimit,
    ) {}

    /**
     * Find the choices for an account's destinations.
     *
     * @param  AlertDestinationsQuery  $destinations
     * @param  TwilioAlerts  $twilio
     * @param  string  $accountId
     * @return self
     */
    public static function for(AlertDestinationsQuery $destinations, TwilioAlerts $twilio, string $accountId): self
    {
        $phones = $twilio->configured();
        $urls = [AlertDestinationType::Webhook, AlertDestinationType::Slack, AlertDestinationType::Teams, AlertDestinationType::Discord];
        $types = [];
        foreach (AlertDestinationType::cases() as $type) {
            if (! $type->isPhone() || $phones) {
                $types[] = ['value' => $type->value, 'label' => $type->label(), 'followsPerson' => $type->followsPerson(), 'isPhone' => $type->isPhone(), 'usesUrl' => in_array($type, $urls, true)];
            }
        }

        return new self(
            types: $types,
            schedules: array_values(OnCallSchedule::query()->where('account_id', $accountId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (OnCallSchedule $schedule): array => ['value' => 'schedule:'.$schedule->id, 'label' => __('On call: :schedule', ['schedule' => $schedule->name])])->all()),
            members: array_map(fn (User $member): array => ['value' => (string) $member->id, 'label' => $member->name.' ('.$member->email.')'], $destinations->recipients($accountId)),
            phones: $phones,
            dailyPhoneLimit: (int) config('services.twilio.daily_limit'),
        );
    }
}
