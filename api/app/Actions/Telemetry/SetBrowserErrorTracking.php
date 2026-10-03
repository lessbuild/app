<?php

declare(strict_types=1);

namespace App\Actions\Telemetry;

use App\Exceptions\AccountRuleViolation;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class SetBrowserErrorTracking
{
    /**
     * How many origins an environment can accept browser errors from.
     *
     * @var int
     */
    public const MAX_ORIGINS = 10;

    /**
     * Turn browser error tracking on for an environment (creating its public key the first time) with the origins its
     * pages are served from, or off (dropping the key, so old snippets stop sending).
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  bool  $enabled
     * @param  list<string>  $origins  such as https://example.com
     * @return void
     */
    public function handle(User $actor, Environment $environment, bool $enabled, array $origins): void
    {
        Gate::forUser($actor)->authorize('manageService', [$environment->project, 'monitoring']);
        if (! $enabled) {
            $environment->forceFill(['browser_key' => null, 'browser_origins' => null])->save();

            return;
        }
        $clean = [];
        foreach ($origins as $origin) {
            $origin = rtrim(mb_strtolower(trim($origin)), '/');
            if ($origin === '') {
                continue;
            }
            if (preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#', $origin) !== 1) {
                throw new AccountRuleViolation('origins', __(':origin isn’t an origin. Use the scheme and host only, such as https://example.com.', ['origin' => $origin]));
            }
            $clean[$origin] = true;
        }
        if ($clean === [] || count($clean) > self::MAX_ORIGINS) {
            throw new AccountRuleViolation('origins', __('List one to :count origins your pages are served from.', ['count' => self::MAX_ORIGINS]));
        }
        $environment->forceFill([
            'browser_key' => $environment->browser_key ?? 'bpb_'.Str::random(32),
            'browser_origins' => array_keys($clean),
        ])->save();
    }
}
