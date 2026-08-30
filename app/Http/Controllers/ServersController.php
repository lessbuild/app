<?php

namespace App\Http\Controllers;

use App\Actions\Droplet\CreateDropletAction;
use App\Http\Requests\ServerRequest;
use App\Jobs\Server\InitialiseServerJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Region;
use App\Models\Server;
use App\Models\Size;
use App\Services\DigitalOcean;
use App\Services\SshKeyPair;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

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
    public function store(ServerRequest $request, SshKeyPair $keypair): RedirectResponse
    {
        $token = $request->user()->providers()->find($request->input('provider_id'))->token;
        $digitalOcean = new DigitalOcean($token);

        $server = Auth::user()->servers()->create([
            'provider_id' => $request->input('provider_id'),
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
            $ssh = $digitalOcean->createSSH([
                'public_key' => $server->ssh_public_key,
                'name' => $request->input('name'),
            ]);

            $fingerprint = $ssh['fingerprint'] ?? null;
            if (! is_string($fingerprint) || $fingerprint === '') {
                throw new UnexpectedValueException('DigitalOcean returned an incomplete SSH key response.');
            }

            // Persist this immediately so a failed cleanup can be retried when the
            // failed server record is deleted.
            $server->update(['ssh_fingerprint' => $fingerprint]);

            $droplet = (new CreateDropletAction($server, $digitalOcean))->handle([
                'region' => $request->input('region'),
                'size' => $request->input('size'),
                'image' => $request->input('image'),
                'name' => str()->slug($request->input('name')),
                'ssh_keys' => [$fingerprint],
            ]);

            $dropletData = $droplet['droplet'] ?? null;
            if (! is_array($dropletData)
                || ! isset(
                    $dropletData['id'],
                    $dropletData['name'],
                    $dropletData['region']['name'],
                    $dropletData['size']['slug'],
                    $dropletData['image']['name'],
                )) {
                throw new UnexpectedValueException('DigitalOcean returned an incomplete droplet response.');
            }

            $server->update([
                'provider_id' => $request->input('provider_id'),
                'identifier' => $dropletData['id'],
                'name' => $dropletData['name'],
                'region' => $dropletData['region']['name'],
                'size' => $dropletData['size']['slug'],
                'image' => $dropletData['image']['name'],
            ]);

            InitialiseServerJob::dispatch($server);
        } catch (Throwable $exception) {
            report($exception);

            $this->cleanUpSshKey($server, $digitalOcean);

            $server->update([
                'provisioning_status' => Server::STATUS_FAILED,
                'provisioning_error' => str($exception->getMessage())->limit(2000),
            ]);

            return redirect()
                ->route('servers.show', $server)
                ->with('error', __('The cloud server could not be created. Review the error below and try again.'));
        }

        return redirect()->route('servers.show', $server);
    }

    private function cleanUpSshKey(Server $server, DigitalOcean $digitalOcean): void
    {
        if (! $server->ssh_fingerprint) {
            return;
        }

        try {
            if ($digitalOcean->deleteSSHKey($server->ssh_fingerprint)) {
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
}
