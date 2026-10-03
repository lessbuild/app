<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\ApplyServerNodeVersion;
use App\Jobs\Infrastructure\ApplyWebsitePhpVersion;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ChangeRuntimeVersion
{
    /** The Node.js major versions a server can switch to. */
    public const NODE_VERSIONS = ['18', '20', '22', '24'];

    /**
     * Move a website to another PHP version (installed on its server if needed), or a server to another Node.js major
     * version. The change is applied in the background.
     *
     * @param  User  $actor
     * @param  Website|Server  $target
     * @param  string  $version
     * @return void
     */
    public function handle(User $actor, Website|Server $target, string $version): void
    {
        Gate::forUser($actor)->authorize('update', $target);
        if ($target instanceof Website) {
            if (! in_array($version, Website::PHP_VERSIONS, true)) {
                throw ValidationException::withMessages(['php_version' => __('Choose PHP :versions.', ['versions' => implode(', ', Website::PHP_VERSIONS)])]);
            }
            $target->forceFill(['php_version' => $version])->save();
            ApplyWebsitePhpVersion::dispatch($target->id);

            return;
        }
        if (! in_array($version, self::NODE_VERSIONS, true)) {
            throw ValidationException::withMessages(['node_version' => __('Choose Node.js :versions.', ['versions' => implode(', ', self::NODE_VERSIONS)])]);
        }
        $target->forceFill(['node_version' => $version])->save();
        ApplyServerNodeVersion::dispatch($target->id);
    }
}
