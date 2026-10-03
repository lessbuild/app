<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shows how email is set up (never the secrets), what would stop it reaching people, and sends a test message so an
 * admin can prove delivery end to end. The last test's outcome is remembered for a week.
 */
final class EmailDelivery
{
    /**
     * Mailers that accept mail without sending it anywhere.
     *
     * @var list<string>
     */
    public const NON_DELIVERING = ['log', 'array'];

    /**
     * The cache key holding the last test's outcome.
     *
     * @var string
     */
    private const LAST_TEST = 'admin.email.last-test';

    /**
     * Create a new EmailDelivery instance.
     *
     * @param  PlatformAdmins  $admins  Records each test in the admin log.
     */
    public function __construct(private readonly PlatformAdmins $admins) {}

    /**
     * List the mail settings that are safe to show: the mailer and its transport, host, port and sender, but never the
     * username or password.
     *
     * @return list<array{string, string}>
     */
    public function settings(): array
    {
        $name = $this->mailer();
        $mailer = (array) config('mail.mailers.'.$name, []);
        $transport = (string) ($mailer['transport'] ?? $name);
        $settings = [['Mailer', $name], ['Transport', $transport]];
        if ($transport === 'smtp') {
            $settings[] = ['Host', filled($mailer['url'] ?? null) ? 'Set by MAIL_URL' : (string) ($mailer['host'] ?? '')];
            $settings[] = ['Port', (string) ($mailer['port'] ?? '')];
            $settings[] = ['Signs in', filled($mailer['username'] ?? null) ? 'Yes' : 'No'];
        }
        $settings[] = ['From', trim(((string) config('mail.from.name')).' <'.((string) config('mail.from.address')).'>')];
        $settings[] = ['Alert mailer', (string) (config('monitoring.alerts.mailer') ?? $name)];

        return $settings;
    }

    /**
     * List what would stop email reaching people, in the words an admin needs to fix it.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $name = $this->mailer();
        if (in_array($name, self::NON_DELIVERING, true)) {
            $problems[] = __('The :mailer mailer doesn’t send anything, so sign-up verification, password resets, invitations and alerts never arrive. Set MAIL_MAILER (for example smtp, ses, postmark or resend) and its settings in .env.', ['mailer' => $name]);
        }
        if (config('mail.mailers.'.$name) === null) {
            $problems[] = __('MAIL_MAILER names :mailer, which isn’t configured in config/mail.php.', ['mailer' => $name]);
        }
        $from = (string) config('mail.from.address');
        if (! filter_var($from, FILTER_VALIDATE_EMAIL) || Str::endsWith($from, ['@example.com', '@example.org', '@localhost'])) {
            $problems[] = __('The sender address (MAIL_FROM_ADDRESS) is :from. Use an address on your own domain, or mail may be rejected as spam.', ['from' => $from === '' ? __('empty') : $from]);
        }

        return $problems;
    }

    /**
     * Send a test email straight away (not queued) and remember how it went.
     *
     * @param  User  $admin  Who asked for it.
     * @param  string  $to  Where to send it.
     * @return array{sent: bool, to: string, mailer: string, error: string|null, at: string}
     */
    public function sendTest(User $admin, string $to): array
    {
        $mailer = $this->mailer();
        try {
            Mail::mailer($mailer)->raw(
                __("This is a test email from :app, sent by :name from the admin panel.\n\nIf you're reading this, email is working.", ['app' => config('app.name'), 'name' => $admin->name]),
                fn ($message) => $message->to($to)->subject(__(':app test email', ['app' => config('app.name')])),
            );
            $error = null;
        } catch (Throwable $exception) {
            report($exception);
            $error = Str::limit($exception->getMessage(), 500);
        }
        $result = ['sent' => $error === null, 'to' => $to, 'mailer' => $mailer, 'error' => $error, 'at' => now()->toIso8601String()];
        Cache::put(self::LAST_TEST, $result, now()->addWeek());
        $this->admins->record($admin, 'email.test', $error === null ? "Sent a test email to {$to} with {$mailer}" : "A test email to {$to} with {$mailer} failed");

        return $result;
    }

    /**
     * Get the last test's outcome, if there was one this week.
     *
     * @return array{sent: bool, to: string, mailer: string, error: string|null, at: string}|null
     */
    public function lastTest(): ?array
    {
        $result = Cache::get(self::LAST_TEST);
        if (! is_array($result) || ! is_bool($result['sent'] ?? null) || ! is_string($result['to'] ?? null) || ! is_string($result['mailer'] ?? null) || ! is_string($result['at'] ?? null)) {
            return null;
        }

        return ['sent' => $result['sent'], 'to' => $result['to'], 'mailer' => $result['mailer'], 'error' => is_string($result['error'] ?? null) ? $result['error'] : null, 'at' => $result['at']];
    }

    /**
     * Get the name of the default mailer.
     *
     * @return string
     */
    private function mailer(): string
    {
        return (string) config('mail.default');
    }
}
