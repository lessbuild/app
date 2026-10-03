<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Build;
use Illuminate\Support\Str;

final class RecordDestructiveMigrations
{
    /**
     * Keep the destructive statements a deploy's migration check found, for someone to review and approve.
     *
     * @param  Build  $build
     * @param  string  $statements
     * @return void
     */
    public function handle(Build $build, string $statements): void
    {
        $build->forceFill(['destructive_migrations' => Str::limit(trim($statements), 8000)])->save();
    }
}
