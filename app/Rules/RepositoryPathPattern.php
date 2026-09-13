<?php

namespace App\Rules;

use App\Support\RepositoryPath;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RepositoryPathPattern implements ValidationRule
{
    /**
     * Validate a safe relative repository path pattern for automatic deployment filtering.
     *
     * @param  string  $attribute  Validation attribute name.
     * @param  mixed  $value  Candidate relative glob pattern.
     * @param  Closure(string, ?string=): object  $fail  Validation failure callback.
     * @return void Failures are reported through the callback.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (RepositoryPath::normalizePattern($value) === null) {
            $fail(__('Enter a safe relative repository path pattern.'));
        }
    }
}
