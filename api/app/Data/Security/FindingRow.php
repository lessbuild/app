<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Models\SecurityFinding;
use App\Services\Security\HardeningScripts;

final readonly class FindingRow
{
    /**
     * Create a new FindingRow instance.
     *
     * A stored finding as the Security pages list it, with the fix that can be applied from the page, if any.
     *
     * @param  int  $id
     * @param  string  $severity  critical, high, medium, low or info.
     * @param  string  $severityLabel  The severity in the person's language.
     * @param  string  $tone  The badge tone for the severity.
     * @param  string  $source  The check that found it: dependencies, secrets, servers, domains, attacks or access.
     * @param  string  $sourceLabel  The check's name in the person's language.
     * @param  string|null  $subject  What it's about, such as a website or package.
     * @param  string  $title  One line saying what's wrong.
     * @param  string|null  $detail  More about it.
     * @param  string|null  $fix  How to fix it by hand.
     * @param  string|null  $url  Where to read more.
     * @param  string  $status  open, ignored or resolved.
     * @param  string|null  $ignoredReason  Why someone ignored it.
     * @param  string  $lastSeenAt  ISO 8601.
     * @param  string|null  $resolvedAt  ISO 8601.
     * @param  string|null  $fixAction  One of HardeningScripts::ACTIONS' keys when the page can apply the fix.
     * @param  string|null  $fixLabel  What applying the fix does, such as "Turn the firewall on".
     * @param  string|null  $fixWarning  What to expect when the fix is applied.
     * @param  bool  $fixing  Whether the fix is being applied now.
     * @param  string|null  $fixError  Why the last attempt failed.
     */
    public function __construct(
        public int $id,
        public string $severity,
        public string $severityLabel,
        public string $tone,
        public string $source,
        public string $sourceLabel,
        public ?string $subject,
        public string $title,
        public ?string $detail,
        public ?string $fix,
        public ?string $url,
        public string $status,
        public ?string $ignoredReason,
        public string $lastSeenAt,
        public ?string $resolvedAt,
        public ?string $fixAction,
        public ?string $fixLabel,
        public ?string $fixWarning,
        public bool $fixing,
        public ?string $fixError,
    ) {}

    /**
     * Describe a finding.
     *
     * @param  SecurityFinding  $finding
     * @return self
     */
    public static function from(SecurityFinding $finding): self
    {
        $action = $finding->data['fix_action'] ?? null;
        $action = is_string($action) && array_key_exists($action, HardeningScripts::ACTIONS) ? $action : null;

        return new self(
            id: $finding->id,
            severity: $finding->severity,
            severityLabel: $finding->severityLabel(),
            tone: $finding->tone(),
            source: $finding->source,
            sourceLabel: __(SecurityFinding::SOURCES[$finding->source] ?? $finding->source),
            subject: $finding->subject,
            title: $finding->title,
            detail: $finding->detail,
            fix: $finding->fix,
            url: $finding->url,
            status: $finding->status,
            ignoredReason: $finding->ignored_reason,
            lastSeenAt: $finding->last_seen_at->toIso8601String(),
            resolvedAt: $finding->resolved_at?->toIso8601String(),
            fixAction: $action,
            fixLabel: $action === null ? null : (string) __(HardeningScripts::ACTIONS[$action]),
            fixWarning: $action === null ? null : self::warning($action),
            fixing: ($finding->data['fix_status'] ?? null) === 'running',
            fixError: is_string($finding->data['fix_error'] ?? null) ? $finding->data['fix_error'] : null,
        );
    }

    /**
     * Say what applying a fix does to the server, so the person knows before they confirm.
     *
     * @param  string  $action  One of HardeningScripts::ACTIONS' keys.
     * @return string
     */
    private static function warning(string $action): string
    {
        return match ($action) {
            'reboot' => __('The server restarts in one minute; its websites are down while it boots, usually under a minute.'),
            'enable-firewall' => __('Only SSH, the web (80 and 443) and the server’s own firewall rules stay open. Anything else listening on the server stops being reachable from outside.'),
            'apply-updates' => __('Installs the waiting updates now. Services may restart briefly.'),
            default => __('This changes the server’s configuration. BuildPusher keeps its own access; the server is checked again afterwards.'),
        };
    }
}
