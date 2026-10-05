<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Data\Infrastructure\ServerSummary;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\ServerKeys;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use phpseclib4\Crypt\EC;

final class CreateServerImportController
{
    /**
     * Where the session keeps the key made for importing a server.
     *
     * @var string
     */
    public const SESSION_KEY = 'server_import.key';

    /**
     * Describe the form for importing a server: the server types, the Ubuntu versions it supports, and the public half
     * of a key made for this import, so people can add it to root's authorized_keys instead of pasting a private key.
     * The private half stays in this browser's session (encrypted) until the server is inspected.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ServerKeys  $keys
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ServerKeys $keys): JsonResponse
    {
        $stored = $request->session()->get(self::SESSION_KEY);
        $privateKey = is_string($stored) ? rescue(fn (): string => Crypt::decryptString($stored), '', false) : '';
        if ($privateKey === '') {
            $privateKey = EC::createKey('Ed25519')->toString('OpenSSH');
            $request->session()->put(self::SESSION_KEY, Crypt::encryptString($privateKey));
        }

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'types' => ServerSummary::types(),
            'ubuntuVersions' => (array) config('infrastructure.supported_ubuntu_versions'),
            'publicKey' => $keys->publicKeyOf($privateKey).' buildpusher-import',
        ]);
    }
}
