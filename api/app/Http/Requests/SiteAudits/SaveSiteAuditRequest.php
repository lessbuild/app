<?php

declare(strict_types=1);

namespace App\Http\Requests\SiteAudits;

use App\Enums\SiteAuditGoal;
use App\Enums\SiteAuditSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The new-audit wizard and the edit form: the site, the journeys, the competitors and the schedule. */
final class SaveSiteAuditRequest extends FormRequest
{
    /**
     * Get the validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:2048'],
            'goals' => ['array', 'max:6'],
            'goals.*' => ['string', Rule::enum(SiteAuditGoal::class)],
            'custom_goal' => ['nullable', 'string', 'max:300'],
            'competitors' => ['array', 'max:10'],
            'competitors.*.url' => ['required', 'string', 'max:2048'],
            'competitors.*.name' => ['nullable', 'string', 'max:120'],
            'competitors.*.source' => ['nullable', 'string', Rule::in(['customer', 'suggested'])],
            'competitors.*.reason' => ['nullable', 'string', 'max:500'],
            'schedule' => ['required', Rule::enum(SiteAuditSchedule::class)],
        ];
    }

    /**
     * Get the chosen journeys.
     *
     * @return list<string>
     */
    public function goals(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('goals', [])));
    }

    /**
     * Get the competitors as the Action takes them.
     *
     * @return list<array{url: string, name?: string|null, source?: string|null, reason?: string|null}>
     */
    public function competitors(): array
    {
        $competitors = [];
        foreach ((array) $this->validated('competitors', []) as $competitor) {
            $competitors[] = ['url' => (string) $competitor['url'], 'name' => $competitor['name'] ?? null, 'source' => $competitor['source'] ?? null, 'reason' => $competitor['reason'] ?? null];
        }

        return $competitors;
    }
}
