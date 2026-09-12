<?php

namespace App\Http\Controllers;

use App\Actions\Server\CollectServerLogAction;
use App\Actions\Server\CreateServerAction;
use App\Actions\Server\QueueRemoteServerProvisioningRetryAction;
use App\Actions\Server\RetryServerInitializationAction;
use App\Http\Requests\ServerDisplayNameRequest;
use App\Http\Requests\ServerRequest;
use App\Http\Responses\PlainTextLogDownload;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Region;
use App\Models\Server;
use App\Models\Size;
use App\Services\ActivityRecorder;
use App\Services\PlanLimits;
use App\Services\ServerInventoryExporter;
use App\Services\ServerInventoryQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ServersController extends Controller
{
    public function __construct(
        private readonly ServerInventoryExporter $serverInventoryExporter,
        private readonly ServerInventoryQuery $serverInventory,
    ) {}

    /**
     * List all servers.
     */
    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
        $servers = $this->serverInventory->for($request->user(), $filters)
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.servers.index', [
            'servers' => $servers,
            'filters' => $filters,
            'metrics' => $this->serverInventory->metrics($request->user(), $filters),
            'statuses' => $this->serverStatuses(),
        ]);
    }

    /**
     * Stream filtered workspace server inventory with provider details and website counts as private, spreadsheet-safe CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->indexFilters($request);

        return $this->serverInventoryExporter->stream($request->user(), $filters);
    }

    /**
     * Show the resource creation form
     */
    public function create(Request $request, PlanLimits $limits): View
    {
        $types = ServerTypeEnum::cases();
        $providers = $request->user()->workspaceProviders()->forServers()->get();
        $regions = Region::all();
        $sizes = Size::all();
        $recipes = $request->user()->workspaceRecipes()->oldest()->get();
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
            'planUsage' => $limits->usage($request->user(), 'servers'),
        ]);
    }

    /**
     * Authorize server updates and render the form for its display label.
     */
    public function edit(Server $server): View
    {
        $this->authorize('update', $server);

        return view('scenes.servers.edit', ['server' => $server]);
    }

    /**
     * Save the validated display label for an editable server and record activity only when the visible label changes.
     *
     * @return RedirectResponse The server page; labels matching the technical name are stored as null.
     */
    public function update(
        ServerDisplayNameRequest $request,
        Server $server,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $this->authorize('update', $server);

        $oldLabel = $server->label;
        $displayName = $request->validated('display_name');
        if ($displayName === $server->name) {
            $displayName = null;
        }

        $server->update(['display_name' => $displayName]);
        if ($oldLabel !== $server->label) {
            $activity->record(
                $server,
                $server->user_id,
                'server',
                "Server display name changed from \"{$oldLabel}\" to \"{$server->label}\".",
            );
        }

        return redirect()
            ->route('servers.show', $server)
            ->with('success', __('Server display name updated.'));
    }

    /**
     * Validate the server request, invoke provisioning, and map its final state to the server page.
     */
    public function store(
        ServerRequest $request,
        CreateServerAction $create,
    ): RedirectResponse {
        $data = $request->validated();
        $provider = $request->user()->workspaceProviders()->forServers()->findOrFail($data['provider_id']);
        $server = $create->handle(
            $request->user(),
            $provider,
            $request->enum('type', ServerTypeEnum::class),
            $data['name'],
            [
                'region' => $data['region'],
                'size' => $data['size'],
                'image' => $data['image'],
            ],
            $data['recipes'] ?? [],
        );

        if ($server->provisioning_status === Server::STATUS_FAILED) {
            return redirect()
                ->route('servers.show', $server)
                ->with('error', __('The cloud server could not be created. Review the error below and try again.'));
        }

        return redirect()->route('servers.show', $server);
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

    /**
     * Authorize server updates and redirect with the initialization retry action's eligibility result.
     */
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

    /**
     * Authorize server updates and redirect with the remote-provisioning retry action's eligibility result.
     */
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

    /**
     * Authorize server visibility and download an existing snapshot for a supported log type, or fail with 404.
     */
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

    /** @return array{search: ?string, status: ?string, provisioning: ?string} */
    private function indexFilters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $status = $request->string('status')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'status' => in_array($status, $this->serverStatuses(), true) ? $status : null,
            'provisioning' => $request->boolean('provisioning') ? '1' : null,
        ];
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
}
