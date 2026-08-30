<?php

namespace App\Http\Controllers;

use App\Actions\Server\CreateCloudServerAction;
use App\Actions\Server\RetryServerInitializationAction;
use App\Contracts\ServerProvider;
use App\Http\Requests\ServerRequest;
use App\Jobs\Server\InitialiseServerJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Region;
use App\Models\Server;
use App\Models\Size;
use App\Services\ServerProviderResolver;
use App\Services\SshKeyPair;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ServersController extends Controller
{
    /**
     * List all servers.
     */
    public function index(Request $request): View
    {
        $servers = $request->user()->servers()->paginate();

        return view('scenes.servers.index', [
            'servers' => $servers,
        ]);
    }

    /**
     * Show the resource creation form
     */
    public function create(Request $request): View
    {
        $types = ServerTypeEnum::cases();
        $providers = $request->user()->providers()->forServers()->get();
        $regions = Region::all();
        $sizes = Size::all();
        $recipes = $request->user()->recipes()->oldest()->get();
        $images = [
            'ubuntu-22-04-x64' => 'Ubuntu 22.04 (LTS) x64',
            'ubuntu-20-04-x64' => 'Ubuntu 20.04 x86',
            'ubuntu-18-04-x64' => 'Ubuntu 18.04 x86 image',
        ];

        return view('scenes.servers.create', [
            'types' => $types,
            'providers' => $providers,
            'regions' => $regions,
            'sizes' => $sizes,
            'images' => $images,
            'recipes' => $recipes,
        ]);
    }

    /**
     * Store the resource in storage
     */
    public function store(
        ServerRequest $request,
        SshKeyPair $keypair,
        ServerProviderResolver $providers,
        CreateCloudServerAction $createCloudServer,
    ): RedirectResponse {
        $provider = $request->user()->providers()->forServers()->findOrFail($request->integer('provider_id'));
        $cloudProvider = $providers->resolve($provider);

        $server = $request->user()->servers()->create([
            'provider_id' => $provider->id,
            'type' => $request->enum('type', ServerTypeEnum::class),
            'name' => str($request->input('name'))->slug()->limit(31, ''),
            'provisioning_status' => Server::STATUS_QUEUED,
            'ssh_public_key' => $keypair->publicKey(),
            'ssh_private_key' => $keypair->privateKey(),
        ]);

        $recipeAssignments = collect($request->input('recipes', []))
            ->values()
            ->mapWithKeys(fn ($recipeId, $position) => [
                (int) $recipeId => ['position' => $position],
            ]);
        $server->recipes()->sync($recipeAssignments);

        try {
            $fingerprint = $cloudProvider->createSshKey((string) $request->string('name'), $server->ssh_public_key);

            // Persist this immediately so a failed cleanup can be retried when the
            // failed server record is deleted.
            $server->update(['ssh_fingerprint' => $fingerprint]);

            $cloudServer = $createCloudServer->handle($server, $cloudProvider, [
                'region' => $request->input('region'),
                'size' => $request->input('size'),
                'image' => $request->input('image'),
                'name' => str()->slug($request->input('name')),
                'ssh_keys' => [$fingerprint],
            ]);

            $server->update([
                'identifier' => $cloudServer->identifier,
                'name' => $cloudServer->name,
                'region' => $cloudServer->region,
                'size' => $cloudServer->size,
                'image' => $cloudServer->image,
            ]);

            InitialiseServerJob::dispatch($server);
        } catch (Throwable $exception) {
            report($exception);

            $this->cleanUpSshKey($server, $cloudProvider);

            $server->update([
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_error' => str($exception->getMessage())->limit(2000),
                'provisioning_failure_phase' => Server::FAILURE_CREATION,
            ]);

            return redirect()
                ->route('servers.show', $server)
                ->with('error', __('The cloud server could not be created. Review the error below and try again.'));
        }

        return redirect()->route('servers.show', $server);
    }

    private function cleanUpSshKey(Server $server, ServerProvider $provider): void
    {
        if (! $server->ssh_fingerprint) {
            return;
        }

        try {
            if ($provider->deleteSshKey($server->ssh_fingerprint)) {
                $server->update(['ssh_fingerprint' => null]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Delete a droplet
     */
    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('delete', $server);

        try {
            DB::transaction(fn () => $server->delete());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', __('The server could not be deleted: :message', [
                'message' => $exception->getMessage(),
            ]));
        }

        return redirect()
            ->route('servers.index')
            ->with('success', __('Server deleted successfully.'));
    }

    public function retryInitialization(
        Server $server,
        RetryServerInitializationAction $retry,
    ): RedirectResponse {
        $this->authorize('update', $server);

        if (! $retry->handle($server)) {
            return back()->with('info', __('Server initialization is not eligible for retry.'));
        }

        return back()->with('success', __('Server initialization retry queued.'));
    }
}
