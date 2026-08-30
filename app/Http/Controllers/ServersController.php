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

class ServersController extends Controller
{
    /**
     * List all servers.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\View\View
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
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\View\View
     */
    public function create(Request $request): View
    {
        $types = ServerTypeEnum::cases();
        $providers = $request->user()->providers()->get();
        $regions = Region::all();
        $sizes = Size::all();
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
        ]);
    }

    /**
     * Store the resource in storage
     *
     * @param  \App\Http\Requests\ServerRequest  $request
     * @param  \App\Services\SshKeyPair  $keypair
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Exception
     */
    public function store(ServerRequest $request, SshKeyPair $keypair): RedirectResponse
    {
        $token = $request->user()->providers()->find($request->input('provider_id'))->token;
        $digitalOcean = new DigitalOcean($token);

        $server = tap(Auth::user()->servers()->create([
            'provider_id' => $request->input('provider_id'),
            'provisioning_status' => Server::STATUS_QUEUED,
            'keypair' => [
                'public' => $keypair->publicKey(),
                'private' => $keypair->privateKey(),
            ],
        ]), function ($server) use ($digitalOcean, $request) {

            // create ssh
            $ssh = $digitalOcean->createSSH([
                'public_key' => $server->keypair['public'],
                'name' => $request->input('name'),
            ]);

            $droplet = (new CreateDropletAction($server, $digitalOcean))->handle([
                'region' => $request->input('region'),
                'size' => $request->input('size'),
                'image' => $request->input('image'),
                'name' => str()->slug($request->input('name')),
                'ssh_keys' => [$ssh['fingerprint']],
            ]);

            $server->update([
                'provider_id' => $request->input('provider_id'),
                'identifier' => $droplet['droplet']['id'],
                'name' => $droplet['droplet']['name'],
                'region' => $droplet['droplet']['region']['name'],
                'size' => $droplet['droplet']['size']['slug'],
                'image' => $droplet['droplet']['image']['name'],
                'ssh_fingerprint' => $ssh['fingerprint'],
            ]);
        });

        InitialiseServerJob::dispatch($server);

        return redirect()->route('servers.show', $server);
    }

    /**
     * Delete a droplet
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Server  $server
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Exception
     */
    public function destroy(Request $request, Server $server)
    {
        $this->authorize('delete', $server);

        $server->delete();

        return redirect()->route('servers.index');
    }
}
