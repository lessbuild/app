<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RecordDestructiveMigrations;
use App\Models\Build;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** POST /builds/{build}/deployment/callback/migrations: the deploy's migration check reports what it stopped for. */
final class RecordDestructiveMigrationsController
{
    /**
     * Record the destructive statements the deploy found. The URL is signed per build.
     *
     * @param  Request  $request
     * @param  Build  $build
     * @param  RecordDestructiveMigrations  $record
     * @return Response
     */
    public function __invoke(Request $request, Build $build, RecordDestructiveMigrations $record): Response
    {
        $record->handle($build, (string) $request->string('statements'));

        return response()->noContent();
    }
}
