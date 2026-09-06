<?php

namespace App\Http\Controllers;

use App\Actions\Server\PrepareServerProvisioningAction;
use App\Http\Requests\ImportServerRequest;
use App\Jobs\Server\RetryRemoteServerProvisioningJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\ServerLogSnapshot;
use App\Models\ServerImportAssessment;
use App\Services\ActivityRecorder;
use App\Services\PlanLimits;
use App\Services\ServerDiscovery;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use phpseclib3\Crypt\PublicKeyLoader;

class ImportServerController extends Controller
{
    public function create(Request $request, PlanLimits $limits): View
    {
        return view('scenes.servers.import', [
            'types' => ServerTypeEnum::cases(),
            'planUsage' => $limits->usage($request->user(), 'servers'),
        ]);
    }

    public function store(
        ImportServerRequest $request,
        PlanLimits $limits,
        ServerDiscovery $discovery,
    ): RedirectResponse {
        $limits->usage($request->user(), 'servers')['allowed'] || throw ValidationException::withMessages(['plan' => __('Your plan’s server limit has been reached.')]);
        $configuration = [
            'name' => $request->validated('name'), 'type' => $request->validated('type'),
            'public_ip' => $request->validated('public_ip'), 'ssh_port' => $request->integer('ssh_port'),
            'ssh_private_key' => trim($request->validated('ssh_private_key')),
        ];
        try {
            $report = $discovery->inspect($configuration);
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['connection' => str($exception->getMessage())->limit(1000)->toString()]);
        }
        $token = Str::random(64);
        $assessment = ServerImportAssessment::query()->create([
            'organization_id' => $request->user()->current_organization_id,
            'user_id' => $request->user()->id,
            'token_hash' => hash('sha256', $token),
            'configuration' => $configuration,
            'report' => $report,
            'expires_at' => now()->addMinutes(30),
        ]);
        $request->session()->put("server_import_assessment.{$assessment->id}", $token);

        return redirect()->route('servers.import.review', $assessment);
    }

    public function review(Request $request, ServerImportAssessment $assessment): View
    {
        $this->authorizeAssessment($request, $assessment);

        return view('scenes.servers.import-review', ['assessment' => $assessment]);
    }

    public function confirm(
        Request $request,
        ServerImportAssessment $assessment,
        PlanLimits $limits,
        PrepareServerProvisioningAction $prepare,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $this->authorizeAssessment($request, $assessment);
        $configuration = $assessment->configuration;
        $request->validate([
            'confirmation' => ['required', 'string', 'in:'.$configuration['name']],
            'backup_confirmed' => ['accepted'],
            'host_fingerprint_confirmed' => ['accepted'],
        ]);

        $server = $limits->withinLimit($request->user(), 'servers', function ($organization) use ($request, $prepare): Server {
            $assessment = ServerImportAssessment::query()->lockForUpdate()->findOrFail($request->route('assessment')->id);
            $token = (string) $request->session()->get("server_import_assessment.{$assessment->id}");
            if (! $assessment->isUsableBy($request->user(), $token)) throw ValidationException::withMessages(['confirmation' => __('This import assessment expired or was already used. Run the inspection again.')]);
            $configuration = $assessment->configuration;
            $server = $organization->servers()->create([
                'user_id' => $request->user()->id,
                'type' => ServerTypeEnum::from($configuration['type']),
                'name' => str($configuration['name'])->slug()->limit(31, ''),
                'region' => 'External',
                'image' => 'Existing Ubuntu',
                'size' => 'Custom',
                'public_ip' => $configuration['public_ip'],
                'ssh_port' => $configuration['ssh_port'],
                'ssh_private_key' => $configuration['ssh_private_key'],
                'ssh_public_key' => PublicKeyLoader::loadPrivateKey($configuration['ssh_private_key'])->getPublicKey()->toString('OpenSSH'),
                'ssh_host_key' => $assessment->report['known_host'],
                'ssh_host_fingerprint' => $assessment->report['fingerprint'],
                'ssh_key_owned' => false,
                'provisioning_status' => Server::STATUS_QUEUED,
            ]);
            $prepare->handle($server);
            $server->update(['password' => $server->provisioningRootPassword()]);
            $server->logSnapshots()->create([
                'type' => 'provisioning',
                'status' => ServerLogSnapshot::STATUS_QUEUED,
            ]);
            RetryRemoteServerProvisioningJob::dispatch($server->id, $server->provisioning_token)->afterCommit();
            $assessment->update(['consumed_at' => now()]);

            return $server;
        });
        $request->session()->forget("server_import_assessment.{$assessment->id}");
        $activity->record($server, $request->user()->id, 'server', 'Existing server imported and provisioning queued.');

        return redirect()->route('servers.show', $server)
            ->with('success', __('Server imported. BuildPusher is securely connecting and applying the selected runtime.'));
    }

    private function authorizeAssessment(Request $request, ServerImportAssessment $assessment): void
    {
        $token = (string) $request->session()->get("server_import_assessment.{$assessment->id}");
        abort_unless($assessment->isUsableBy($request->user(), $token), 404);
    }
}
