<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertDestinationType: string
{
    case Email = 'email';
    case Webhook = 'webhook';
    case Slack = 'slack';
    case Teams = 'teams';
    case PagerDuty = 'pagerduty';
    case Discord = 'discord';
    case Sms = 'sms';
    case Voice = 'voice';
    case Push = 'push';

    /**
     * Get the destination type's name on the alert destination form.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email'),
            self::Webhook => __('Signed webhook'),
            self::Slack => 'Slack',
            self::Teams => 'Microsoft Teams',
            self::PagerDuty => 'PagerDuty',
            self::Discord => 'Discord',
            self::Sms => __('Text message (SMS)'),
            self::Voice => __('Phone call'),
            self::Push => __('Push notification'),
        };
    }

    /**
     * Determine whether the type sends to a phone number through Twilio.
     *
     * @return bool
     */
    public function isPhone(): bool
    {
        return $this === self::Sms || $this === self::Voice;
    }

    /**
     * Determine whether the type goes to a person (a chosen member, or whoever is on call): email and push.
     *
     * @return bool
     */
    public function followsPerson(): bool
    {
        return $this === self::Email || $this === self::Push;
    }
}
