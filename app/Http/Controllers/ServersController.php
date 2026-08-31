<?php

namespace App\Http\Controllers;

use App\Actions\Server\CollectServerLogAction;
use App\Actions\Server\CreateCloudServerAction;
use App\Actions\Server\QueueRemoteServerProvisioningRetryAction;
use App\Actions\Server\RetryServerInitializationAction;
use App\Contracts\ServerProvider;
use App\Http\Requests\ServerRequest;
use App\Http\Responses\PlainTextLogDownload;
use App\Jobs\Server\InitialiseServerJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Region;
use App\Models\Server;
use App\Models\Size;
use App\Services\ServerProviderResolver;
use App\Services\SshKeyPair;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ServersController extends Controller
{
    /**
     * List all servers.
     */
    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
        $servers = $this->filteredServers($request, $filters)
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.servers.index', [
            'servers' => $servers,
            'filters' => $filters,
            'statuses' => $this->serverStatuses(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->indexFilters($request);
        $filename = 'lessbuild-servers-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Server ID',
                'Name',
                'Cloud identifier',
                'Type',
                'Region',
                'Size',
                'Image',
                'Public IP',
                'Private IP',
                'Provider',
                'Provider type',
                'Status',
                'Website count',
                'Provisioned at',
                'Created at',
            ], ',', '"', '');

            $this->filteredServers($request, $filters)
                ->with('provider')
                ->withCount('websites')
                ->latest('servers.id')
                ->lazy(250)
                ->each(function (Server $server) use ($output): void {
                    fputcsv($output, [
                        $server->id,
                        $this->csvCell($server->name),
                        $this->csvCell($server->identifier),
                        $this->csvCell($server->type?->value),
                        $this->csvCell($server->region),
                        $this->csvCell($server->size),
                        $this->csvCell($server->image),
                        $this->csvCell($server->public_ip),
                        $this->csvCell($server->private_ip),
                        $this->csvCell($server->provider?->name),
                        $this->csvCell($server->provider?->provider),
                        $this->csvCell($server->provisioning_status),
                        $server->websites_count,
                        $server->provisioned_at?->toIso8601String(),
                        $server->created_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
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
        $server->captureProvisioningRecipes();

        try {
            $sshKey = $cloudProvider->createSshKey((string) $request->string('name'), $server->ssh_public_key);

            // Persist this immediately so a failed cleanup can be retried when the
            // failed server record is deleted.
            $server->update([
                'ssh_fingerprint' => $sshKey->fingerprint,
                'ssh_key_owned' => $sshKey->created,
            ]);

            $cloudServer = $createCloudServer->handle($server, $cloudProvider, [
                'region' => $request->input('region'),
                'size' => $request->input('size'),
                'image' => $request->input('image'),
                'name' => str()->slug($request->input('name')),
                'ssh_keys' => [$sshKey->fingerprint],
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
        if (! $server->ssh_fingerprint || ! $server->ssh_key_owned) {
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

    public function retryRemoteProvisioning(
        Server $server,
        QueueRemoteServerProvisioningRetryAction $retry,
    ): RedirectResponse {
        $this->authorize('update', $server);

        if (! $retry->handle($server)) {
            return back()->with('info', __('Remote server provisioning is not eligible for retry.'));
        }

        return back()->with('success', __('Remote server provisioning retry queued.'));
    }

    public function downloadLog(
        Server $server,
        string $type,
        PlainTextLogDownload $download,
    ): Response {
        $this->authorize('view', $server);
        abort_unless(in_array($type, CollectServerLogAction::TYPES, true), 404);

        $snapshot = $server->logSnapshots()
            ->where('type', $type)
            ->whereNotNull('log')
            ->firstOrFail();

        return $download->make(
            $snapshot->log,
            "lessbuild-server-{$server->id}-{$type}.log",
        );
    }

    /** @return array{search: ?string, status: ?string} */
    private function indexFilters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $status = $request->string('status')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'status' => in_array($status, $this->serverStatuses(), true) ? $status : null,
        ];
    }

    /** @param array{search: ?string, status: ?string} $filters */
    private function filteredServers(Request $request, array $filters): HasMany
    {
        return $request->user()->servers()
            ->when($filters['search'], function ($query, string $value): void {
                $query->where(function ($query) use ($value): void {
                    $query
                        ->where('name', 'like', "%{$value}%")
                        ->orWhere('identifier', 'like', "%{$value}%")
                        ->orWhere('public_ip', 'like', "%{$value}%")
                        ->orWhere('private_ip', 'like', "%{$value}%");
                });
            })
            ->when($filters['status'], fn ($query, string $value) => $query
                ->where('provisioning_status', $value));
    }

    /** @return list<string> */
    private function serverStatuses(): array
    {
        return [
            Server::STATUS_QUEUED,
            Server::STATUS_WAITING_FOR_IP,
            Server::STATUS_PROVISIONING,
            Server::STATUS_ACTIVE,
            Server::STATUS_FAILED,
        ];
    }

    private function csvCell(string|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\0", '', (string) $value);

        return preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
