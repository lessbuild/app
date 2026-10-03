<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use App\Actions\Deploy\RequestVariableChange;
use App\Actions\Deploy\SaveEnvironmentVariable;
use App\Models\Environment;
use App\Models\StorageBucket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AttachStorageBucket
{
    /**
     * Create a new AttachStorageBucket instance.
     *
     * @param  SaveEnvironmentVariable  $save  Sets each variable.
     * @param  RequestVariableChange  $request  Asks for approval where the environment needs it.
     */
    public function __construct(private readonly SaveEnvironmentVariable $save, private readonly RequestVariableChange $request) {}

    /**
     * Give an environment of the bucket's project the AWS_* settings for the bucket (the keys as secrets), so Laravel's
     * s3 disk uses it after the next deploy. Where variable changes need a second person, they're asked for instead.
     *
     * @param  User  $actor
     * @param  StorageBucket  $bucket
     * @param  Environment  $environment
     * @return bool whether the variables were set now (false: waiting for approval)
     *
     * @throws ValidationException
     */
    public function handle(User $actor, StorageBucket $bucket, Environment $environment): bool
    {
        Gate::forUser($actor)->authorize('manageService', [$bucket->project, 'infrastructure']);
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        if ($environment->project_id !== $bucket->project_id) {
            throw ValidationException::withMessages(['environment_id' => __('Choose one of this project’s environments.')]);
        }
        $now = ! $environment->require_variable_approval;
        foreach ($bucket->environmentVariables() as $key => $variable) {
            $payload = ['key' => $key, 'value' => $variable['value'], 'is_secret' => $variable['secret'], 'scope' => 'runtime', 'rotation_due_at' => null];
            $now ? $this->save->handle($actor, $environment, $payload) : $this->request->handle($actor, $environment, 'save', $payload);
        }
        $bucket->forceFill(['environment_id' => $environment->id])->save();

        return $now;
    }
}
