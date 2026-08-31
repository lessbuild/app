<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class GitBranch implements ValidationRule
{
    private const PATTERN = '/^(?![-\/]|.*(?:\/\.|\.\.|\/\/|@\{|[~^:?*\[\\\\]))(?!.*[\/.]$)[A-Za-z0-9._\/-]+$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail(__('Enter a valid Git branch name.'));
        }
    }
}
