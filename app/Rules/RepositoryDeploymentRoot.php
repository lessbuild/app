<?php

namespace App\Rules;

use App\Support\RepositoryPath;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RepositoryDeploymentRoot implements ValidationRule
{
    /**
     * Validate an optional safe relative directory inside a checked-out repository.
     *
     * @param  string  $attribute  Validation attribute name.
     * @param  mixed  $value  Candidate service root.
     * @param  Closure(string, ?string=): object  $fail  Validation failure callback.
     * @return void Failures are reported through the callback.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            RepositoryPath::normalizeRoot($value);
        } catch (\InvalidArgumentException) {
            $fail(__('Enter a safe relative service root directory.'));
        }
    }
}
