<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Recognisable credentials: patterns for keys and tokens that providers issue in a distinctive format, so they can be
 * found in code and logs with few false alarms. Matches are only ever stored redacted.
 */
final class SecretPatterns
{
    /**
     * The patterns (POSIX extended, usable by grep -E and by PHP), with a name and severity for each.
     *
     * @var array<string, array{name: string, pattern: string, severity: string}>
     */
    public const RULES = [
        'private-key' => ['name' => 'Private key', 'pattern' => '-----BEGIN (RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY-----', 'severity' => 'critical'],
        'aws-access-key' => ['name' => 'AWS access key', 'pattern' => '(AKIA|ASIA)[0-9A-Z]{16}', 'severity' => 'critical'],
        'stripe-live-key' => ['name' => 'Stripe live secret key', 'pattern' => '(sk|rk)_live_[0-9A-Za-z]{20,}', 'severity' => 'critical'],
        'github-token' => ['name' => 'GitHub token', 'pattern' => '(gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{60,})', 'severity' => 'critical'],
        'gitlab-token' => ['name' => 'GitLab token', 'pattern' => 'glpat-[A-Za-z0-9_-]{20,}', 'severity' => 'critical'],
        'slack-token' => ['name' => 'Slack token', 'pattern' => 'xox[baprs]-[A-Za-z0-9-]{10,}', 'severity' => 'high'],
        'slack-webhook' => ['name' => 'Slack webhook', 'pattern' => 'https://hooks\.slack\.com/services/T[A-Z0-9]+/B[A-Z0-9]+/[A-Za-z0-9]{16,}', 'severity' => 'high'],
        'google-api-key' => ['name' => 'Google API key', 'pattern' => 'AIza[0-9A-Za-z_-]{35}', 'severity' => 'high'],
        'sendgrid-key' => ['name' => 'SendGrid API key', 'pattern' => 'SG\.[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}', 'severity' => 'high'],
        'mailgun-key' => ['name' => 'Mailgun API key', 'pattern' => 'key-[0-9a-f]{32}', 'severity' => 'high'],
        'twilio-key' => ['name' => 'Twilio API key', 'pattern' => 'SK[0-9a-f]{32}', 'severity' => 'high'],
        'openai-key' => ['name' => 'OpenAI API key', 'pattern' => 'sk-(proj-)?[A-Za-z0-9_-]{20,}T3BlbkFJ[A-Za-z0-9_-]{20,}', 'severity' => 'high'],
        'anthropic-key' => ['name' => 'Anthropic API key', 'pattern' => 'sk-ant-[A-Za-z0-9_-]{20,}', 'severity' => 'high'],
        'laravel-app-key' => ['name' => 'Laravel APP_KEY', 'pattern' => 'APP_KEY=base64:[A-Za-z0-9+/]{43}=', 'severity' => 'medium'],
    ];

    /**
     * Find every credential in a piece of text.
     *
     * @param  string  $text
     * @return list<array{rule: string, match: string}>
     */
    public static function find(string $text): array
    {
        $found = [];
        foreach (self::RULES as $rule => $definition) {
            if (preg_match_all('~'.$definition['pattern'].'~', $text, $matches) > 0) {
                foreach (array_unique($matches[0]) as $match) {
                    $found[] = ['rule' => $rule, 'match' => $match];
                }
            }
        }

        return $found;
    }

    /**
     * Get which rule a single matched string belongs to.
     *
     * @param  string  $match
     * @return string|null
     */
    public static function ruleFor(string $match): ?string
    {
        foreach (self::RULES as $rule => $definition) {
            if (preg_match('~'.$definition['pattern'].'~', $match) === 1) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Show just enough of a secret to recognise it: its first four characters and its length.
     *
     * @param  string  $secret
     * @return string
     */
    public static function redact(string $secret): string
    {
        return mb_substr($secret, 0, 4).str_repeat('•', 8).' ('.mb_strlen($secret).' characters)';
    }

    /**
     * Build the grep arguments that search for every pattern.
     *
     * @return string
     */
    public static function grepArguments(): string
    {
        return implode(' ', array_map(fn (array $definition): string => '-e '.escapeshellarg($definition['pattern']), self::RULES));
    }
}
