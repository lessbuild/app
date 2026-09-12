<?php

namespace App\Http\Requests;

use App\Models\ServerImportAssessment;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ConfirmServerImportRequest extends FormRequest
{
    /**
     * Preserve the assessment protocol's deliberate 404 concealment before confirmation validation.
     *
     * A session token, owner identity, expiry and single-use state are part of the assessment
     * protocol rather than an ordinary resource policy. Throwing here keeps invalid or foreign
     * assessments indistinguishable from missing assessments and prevents validation from flashing
     * confirmation input for an unusable assessment.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $assessment = $this->assessment();

        if ($user === null || ! $assessment->isUsableBy($user, $this->token())) {
            throw new NotFoundHttpException;
        }

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $configuration = $this->assessment()->configuration;

        return [
            'confirmation' => ['required', 'string', 'in:'.$configuration['name']],
            'backup_confirmed' => ['accepted'],
            'host_fingerprint_confirmed' => ['accepted'],
        ];
    }

    public function assessment(): ServerImportAssessment
    {
        $assessment = $this->route('assessment');

        if (! $assessment instanceof ServerImportAssessment) {
            throw new NotFoundHttpException;
        }

        return $assessment;
    }

    public function token(): string
    {
        return (string) $this->session()->get("server_import_assessment.{$this->assessment()->id}");
    }
}
