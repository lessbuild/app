<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Data\Audit\AuditLogFilters;
use App\Enums\AuditAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

/** The audit log's filters from the query string. Values that don't fit the account are ignored rather than rejected. */
final class AuditLogRequest extends FormRequest
{
    /**
     * Get the validation rules: none, because unknown filter values are simply ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Build the filters, keeping only the account's own projects and members, known categories and real dates.
     *
     * @param  array<array-key, string>  $projectIds
     * @param  array<array-key, string>  $memberIds
     * @return AuditLogFilters
     */
    public function filters(array $projectIds, array $memberIds): AuditLogFilters
    {
        $pick = fn (string $key, array $allowed): ?string => in_array($value = $this->string($key)->toString(), $allowed, true) ? $value : null;

        return new AuditLogFilters(
            projectId: $pick('project', $projectIds),
            actorId: $pick('person', $memberIds),
            category: $pick('category', array_keys(AuditAction::CATEGORIES)),
            from: $this->day('from'),
            to: $this->day('to'),
        );
    }

    /**
     * Read a Y-m-d date from the query string, or null when it's missing or invalid.
     *
     * @param  string  $key
     * @return CarbonImmutable|null
     */
    private function day(string $key): ?CarbonImmutable
    {
        $value = $this->string($key)->toString();
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
