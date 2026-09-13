<?php

namespace App\Actions\Observability;

use App\Data\ObservabilityInvestigationViewData;
use App\Models\Environment;
use App\Models\ObservabilityInvestigationView;
use App\Models\Organization;
use App\Models\Repository;
use App\Models\User;
use App\Policies\EnvironmentPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateObservabilityInvestigationViewAction
{
    public function __construct(
        private readonly EnvironmentPolicy $environmentPolicy,
    ) {}

    /**
     * Create an organization-owned, expiring view after rechecking environment access and service identity.
     *
     * @param  Organization  $organization  Current workspace that owns the view.
     * @param  Environment  $environment  Environment whose bounded evidence URL is saved.
     * @param  User  $actor  Account creating the named view.
     * @param  ObservabilityInvestigationViewData  $data  Immutable normalized view input.
     * @return ObservabilityInvestigationView The persisted named view.
     */
    public function handle(
        Organization $organization,
        Environment $environment,
        User $actor,
        ObservabilityInvestigationViewData $data,
    ): ObservabilityInvestigationView {
        if (! $this->environmentPolicy->view($actor, $environment)) {
            throw new AuthorizationException;
        }

        if ((int) $environment->project->organization_id !== (int) $organization->id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($organization, $environment, $actor, $data): ObservabilityInvestigationView {
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $now = now();

            if ($data->filters->serviceId !== null
                && ! Repository::query()
                    ->whereKey($data->filters->serviceId)
                    ->where('organization_id', $lockedOrganization->id)
                    ->where('website_id', $environment->website_id)
                    ->exists()) {
                throw ValidationException::withMessages([
                    'service' => __('The selected service is no longer part of this environment.'),
                ]);
            }

            $name = mb_strtolower($data->name);
            if ($lockedOrganization->investigationViews()
                ->where('environment_id', $environment->id)
                ->where('expires_at', '>', $now)
                ->whereRaw('LOWER(name) = ?', [$name])
                ->exists()) {
                throw ValidationException::withMessages([
                    'name' => __('A named investigation with this name already exists for the environment.'),
                ]);
            }

            if ($lockedOrganization->investigationViews()
                ->where('expires_at', '>', $now)
                ->count() >= ObservabilityInvestigationView::MAX_ACTIVE_PER_ORGANIZATION) {
                throw ValidationException::withMessages([
                    'name' => __('This workspace has reached its active investigation view limit.'),
                ]);
            }

            return $lockedOrganization->investigationViews()->create([
                'public_id' => (string) Str::uuid(),
                'environment_id' => $environment->id,
                'created_by' => $actor->id,
                'name' => $data->name,
                'filters' => $data->filters->queryParameters(),
                'expires_at' => $now->copy()->addDays($data->expiresInDays),
            ]);
        });
    }
}
