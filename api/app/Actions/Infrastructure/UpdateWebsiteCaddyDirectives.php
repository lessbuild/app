<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\ApplyWebsiteDomains;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateWebsiteCaddyDirectives
{
    /**
     * Create a new UpdateWebsiteCaddyDirectives instance.
     *
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Save a website's own Caddy directives and apply them. Caddy checks the whole configuration first; if it refuses,
     * the previous file stays in place and the error is shown on the website.
     *
     * @param  User  $actor
     * @param  Website  $website
     * @param  string|null  $directives
     * @return void
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Website $website, ?string $directives): void
    {
        Gate::forUser($actor)->authorize('update', $website);
        $directives = trim(str_replace("\r\n", "\n", (string) $directives));
        if (mb_strlen($directives) > 5000) {
            throw ValidationException::withMessages(['caddy_directives' => __('Keep the directives under 5,000 characters.')]);
        }
        if (str_contains($directives, "\0") || substr_count($directives, '{') !== substr_count($directives, '}')) {
            throw ValidationException::withMessages(['caddy_directives' => __('Every { needs a matching }.')]);
        }
        $website->forceFill(['caddy_directives' => $directives !== '' ? $directives : null])->save();
        $this->audit->handle(AuditAction::WebsiteUpdated, $actor, $website->server->account_id ?? null, ['website' => $website->name]);
        if ($website->provisioning_status === Website::STATUS_ACTIVE) {
            ApplyWebsiteDomains::dispatch($website->id)->afterCommit();
        }
    }
}
