<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Data\ProjectDetails;
use App\Domain\Projects\Enums\EnvironmentKind;
use App\Domain\Projects\Events\ProjectCreated;
use App\Domain\Projects\Models\Environment;
use App\Domain\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CreateProject
{
    /** Create a project with its Production environment. */
    public function handle(User $actor, Account $account, ProjectDetails $details): Project
    {
        Gate::forUser($actor)->authorize('create', [Project::class, $account]);

        $project = DB::transaction(function () use ($actor, $account, $details): Project {
            $project = new Project;
            $project->forceFill([
                'account_id' => $account->id,
                'created_by_id' => $actor->id,
                'name' => trim($details->name),
                'slug' => $this->uniqueSlug($account, $details->name),
                'description' => $details->description !== null && trim($details->description) !== '' ? trim($details->description) : null,
            ])->save();

            $production = new Environment;
            $production->project()->associate($project);
            $production->forceFill(['name' => EnvironmentKind::Production->label(), 'slug' => 'production', 'kind' => EnvironmentKind::Production])->save();

            return $project;
        });

        ProjectCreated::dispatch($project, $actor);

        return $project;
    }

    private function uniqueSlug(Account $account, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        for ($suffix = 2; Project::query()->where('account_id', $account->id)->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
