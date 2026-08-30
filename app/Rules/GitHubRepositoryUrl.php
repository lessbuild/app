<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class GitHubRepositoryUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(
            '/^github\.com\/[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\/[A-Za-z0-9._-]{1,100}\.git$/',
            $value,
        )) {
            $fail(__('The :attribute must identify a GitHub repository, such as github.com/owner/project.git.', [
                'attribute' => $attribute,
            ]));

            return;
        }

        $repository = str($value)->afterLast('/')->beforeLast('.git')->toString();
        if (in_array($repository, ['.', '..'], true)) {
            $fail(__('The :attribute must identify a valid GitHub repository.', ['attribute' => $attribute]));
        }
    }
}
