<?php

namespace App\Http\Requests;

use App\Models\StatusPage;
use Illuminate\Foundation\Http\FormRequest;

class SubscribeToStatusPageRequest extends FormRequest
{
    /** @var StatusPage|null Published status page resolved before email validation. */
    private ?StatusPage $statusPage = null;

    /** Public subscription requests have no authenticated actor to authorize. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the subscriber address after resolving the published page.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:254'],
        ];
    }

    /** Return the published status page resolved at the original controller boundary. */
    public function statusPage(): StatusPage
    {
        return $this->statusPage ?? throw new \LogicException('The status page has not been resolved.');
    }

    /** Return the validated address normalized with the same lowercase behavior as before extraction. */
    public function email(): string
    {
        return strtolower((string) $this->validated('email'));
    }

    /** Preserve published-page lookup and its 404-before-validation ordering. */
    protected function prepareForValidation(): void
    {
        $this->statusPage ??= StatusPage::query()
            ->where('slug', (string) $this->route('slug'))
            ->where('is_published', true)
            ->firstOrFail();
    }
}
