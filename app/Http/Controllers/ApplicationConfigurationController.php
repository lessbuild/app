<?php

namespace App\Http\Controllers;

use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationReview;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Website;
use App\Services\ApplicationConfigurationCancellation;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationResults;
use App\Services\ApplicationConfigurationRetries;
use App\Services\ApplicationConfigurationReviews;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

class ApplicationConfigurationController extends Controller
{
    private function access(Request $request, Project $project): void
    {
        $this->authorize('view', $project);
        abort_unless($project->organization->permits($request->user(), 'manage'), 403);
    }

    public function create(Request $request, Project $project)
    {
        $this->access($request, $project);

        return view('scenes.projects.configuration', [
            'project' => $project, 'review' => null, 'plan' => null, 'application' => null,
            'recentApplications' => ConfigurationApplication::query()->whereHas('review', fn ($query) => $query->where('project_id', $project->id))
                ->latest('id')->limit(20)->get(['id', 'configuration_review_id', 'status', 'created_at']),
            'websites' => Website::query()->where('organization_id', $project->organization_id)
                ->whereHas('server', fn ($query) => $query->where('organization_id', $project->organization_id))
                ->orderBy('name')->paginate(25, ['id', 'name', 'url'], 'sites_page'),
            'repositories' => Repository::query()->where('organization_id', $project->organization_id)
                ->orderBy('name')->paginate(25, ['id', 'name', 'website_id', 'branch'], 'repos_page'),
            'secrets' => EnvironmentVariable::query()->where('is_secret', true)
                ->whereHas('environment.project', fn ($query) => $query->where('organization_id', $project->organization_id))
                ->with('environment:id,name,project_id')->orderBy('key')
                ->paginate(25, ['id', 'environment_id', 'key', 'scope'], 'secrets_page'),
        ]);
    }

    public function store(Request $request, Project $project, ApplicationConfigurationReviews $reviews)
    {
        $this->access($request, $project);
        try {
            $data = $request->validate(['document' => 'required|string|max:50000', 'bindings' => 'required|string|max:20000']);
            $bindings = json_decode($data['bindings'], true, 20, JSON_THROW_ON_ERROR);
            if (! is_array($bindings)) {
                throw ValidationException::withMessages(['bindings' => 'Bindings must be a JSON object.']);
            }
            $review = $reviews->create($project, $request->user(), $data['document'], $bindings);
        } catch (JsonException) {
            return back()->withErrors(['bindings' => 'Bindings must be a valid JSON object.']);
        } catch (ValidationException $exception) {
            // Never flash submitted commands or binding input into session storage.
            return back()->withErrors($exception->errors());
        }

        return redirect()->route('projects.configuration.review', [$project, $review]);
    }

    public function show(Request $request, Project $project, ConfigurationReview $review, ApplicationConfigurationReviews $reviews)
    {
        $this->access($request, $project);
        abort_unless((int) $review->project_id === (int) $project->id, 404);
        $application = ConfigurationApplication::query()->where('configuration_review_id', $review->id)->with('operations')->first();
        abort_unless($application || (int) $review->requested_by === (int) $request->user()->id, 404);
        if ($application) {
            $application = app(ApplicationConfigurationResults::class)->refresh($application);
        }
        try {
            $plan = $application ? $review->summary : $reviews->inspect($review, $request->user());
        } catch (ValidationException $exception) {
            return response()->view('scenes.projects.configuration', [
                'project' => $project, 'review' => $review, 'application' => null, 'plan' => null,
                'reviewError' => collect($exception->errors())->flatten()->first(),
            ], 422);
        }

        return view('scenes.projects.configuration', compact('project', 'review', 'plan', 'application'));
    }

    public function cancel(Request $request, Project $project, ConfigurationReview $review, ConfigurationOperation $operation, ApplicationConfigurationCancellation $cancellation)
    {
        $this->access($request, $project);
        abort_unless((int) $review->project_id === (int) $project->id, 404);
        $application = ConfigurationApplication::query()->where('configuration_review_id', $review->id)->firstOrFail();
        abort_unless($application->relatedOperations()->whereKey($operation->id)->exists(), 404);
        if ($request->except('_token') !== []) {
            return back()->withErrors(['operation' => 'Cancel accepts only the operation identity.']);
        }
        try {
            $cancellation->cancel($operation, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()->route('projects.configuration.review', [$project, $review]);
    }

    public function retry(Request $request, Project $project, ConfigurationReview $review, ConfigurationOperation $operation, ApplicationConfigurationRetries $retries)
    {
        $this->access($request, $project);
        abort_unless((int) $review->project_id === (int) $project->id && (int) $review->requested_by === (int) $request->user()->id, 404);
        $application = ConfigurationApplication::query()->where('configuration_review_id', $review->id)->firstOrFail();
        abort_unless($application->relatedOperations()->whereKey($operation->id)->exists(), 404);
        if ($request->except('_token') !== []) {
            return back()->withErrors(['operation' => 'Retry accepts only the failed operation identity.']);
        }
        try {
            $retries->retry($operation, $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()->route('projects.configuration.review', [$project, $review]);
    }

    public function apply(Request $request, Project $project, ConfigurationReview $review, ApplicationConfigurationReconciler $reconciler)
    {
        $this->access($request, $project);
        abort_unless((int) $review->project_id === (int) $project->id, 404);
        if ($request->except('_token') !== []) {
            return back()->withErrors(['review' => 'Apply accepts only the saved review.']);
        }
        try {
            $reconciler->apply($review, $request->user());
        } catch (ValidationException $exception) {
            return redirect()->route('projects.configuration.create', $project)->withErrors($exception->errors());
        }

        return redirect()->route('projects.configuration.review', [$project, $review]);
    }
}
