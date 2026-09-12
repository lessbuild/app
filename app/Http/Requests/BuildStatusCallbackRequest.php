<?php

namespace App\Http\Requests;

use App\Services\RepositoryDeploymentPlan;
use Illuminate\Foundation\Http\FormRequest;

class BuildStatusCallbackRequest extends FormRequest
{
    private ?int $finalStage = null;

    /** Signed callback middleware, rather than a user policy, authorizes this machine-to-machine request. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the monotonic deployment stage against the current deployment plan.
     *
     * @return array{status: list<string>}
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'integer', 'min:0', 'max:'.$this->finalStage()],
        ];
    }

    /** Return the validated deployment stage as an integer. */
    public function status(): int
    {
        return (int) $this->validated('status');
    }

    /** Return the plan boundary used to validate this callback. */
    public function finalStage(): int
    {
        return $this->finalStage ??= app(RepositoryDeploymentPlan::class)->finalStage();
    }
}
