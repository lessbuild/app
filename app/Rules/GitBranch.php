<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class GitBranch implements ValidationRule
{
    private const PATTERN = '/^(?![-\/]|.*(?:\/\.|\.\.|\/\/|@\{|[~^:?*\[\\\\]))(?!.*[\/.]$)[A-Za-z0-9._\/-]+$/';

    /**
     * Reject branch names outside the supported Git reference syntax.
     *
     * @param  string  $attribute  Validator attribute name.
     * @param  mixed  $value  Candidate branch; non-string values fail validation.
     * @param  Closure(string, ?string=): object  $fail  Records a failure and returns a potentially translated validation message.
     * @return void Failures are reported through the callback.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail(__('Enter a valid Git branch name.'));
        }
    }
}
