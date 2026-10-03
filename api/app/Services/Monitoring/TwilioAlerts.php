<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\Monitoring\AlertDeliveryResult;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Sends alerts as text messages or phone calls through Twilio, within a daily limit per number. */
final class TwilioAlerts
{
    /**
     * Determine whether Twilio is set up: an account SID, auth token and sending number.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return filled(config('services.twilio.account_sid')) && filled(config('services.twilio.auth_token')) && filled(config('services.twilio.from'));
    }

    /**
     * Text or call a number with the alert. Twilio's answer is judged like the other providers': accepted, retried on
     * rate limits and server errors, failed otherwise.
     *
     * @param  AlertDestinationType  $type  Sms or Voice
     * @param  string  $number  E.164, e.g. +447700900123
     * @param  string  $message  what to say
     * @return AlertDeliveryResult
     */
    public function send(AlertDestinationType $type, string $number, string $message): AlertDeliveryResult
    {
        if (! $this->configured()) {
            return new AlertDeliveryResult(AlertDeliveryStatus::Failed, 'twilio_unconfigured');
        }
        if (preg_match('/\A\+[1-9][0-9]{7,14}\z/', $number) !== 1) {
            return new AlertDeliveryResult(AlertDeliveryStatus::Failed, 'phone_invalid');
        }
        $key = 'twilio:'.hash('sha256', $number).':'.now('UTC')->format('Y-m-d');
        Cache::add($key, 0, now('UTC')->endOfDay());
        if (Cache::increment($key) > max(1, (int) config('services.twilio.daily_limit'))) {
            return new AlertDeliveryResult(AlertDeliveryStatus::Failed, 'phone_daily_limit');
        }
        $sid = (string) config('services.twilio.account_sid');
        $fields = ['To' => $number, 'From' => (string) config('services.twilio.from')];
        if ($type === AlertDestinationType::Voice) {
            $said = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $fields['Twiml'] = "<Response><Say>{$said}</Say><Pause length=\"1\"/><Say>{$said}</Say></Response>";
            $endpoint = 'Calls.json';
        } else {
            $fields['Body'] = mb_substr($message, 0, 320);
            $endpoint = 'Messages.json';
        }
        try {
            $response = Http::asForm()->withBasicAuth($sid, (string) config('services.twilio.auth_token'))->connectTimeout(3)->timeout(10)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/{$endpoint}", $fields);
        } catch (Throwable) {
            return new AlertDeliveryResult(AlertDeliveryStatus::Retrying, 'transport_result_unknown');
        }
        $status = $response->status();

        return match (true) {
            $response->successful() => new AlertDeliveryResult(AlertDeliveryStatus::Accepted, httpStatus: $status),
            $status === 429 || $status >= 500 => new AlertDeliveryResult(AlertDeliveryStatus::Retrying, 'provider_retryable', $status),
            default => new AlertDeliveryResult(AlertDeliveryStatus::Failed, 'provider_rejected', $status),
        };
    }
}
