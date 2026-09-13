<?php

namespace App\Http\Requests;

use App\Models\Repository;
use App\Support\RepositoryPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RepositoryImpactPreviewRequest extends FormRequest
{
    public const MAX_INPUT_BYTES = 105000;

    /**
     * Require the workspace read ability before validating preview input.
     *
     * This preserves authorization-before-validation behavior for a read that can
     * otherwise reveal the names and deployment configuration of every target.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Repository::class) ?? false;
    }

    /**
     * Validate the bounded textarea and unavailable-path indicator.
     *
     * The fields are optional only when the page is first opened; the after hook
     * requires exactly one preview mode when the user submits input.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'changed_paths' => ['nullable', 'string', 'max:'.self::MAX_INPUT_BYTES],
            'changed_paths_unavailable' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Validate each newline-delimited path without changing the submitted text shown after an error.
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->hasPreviewInput()) {
                return;
            }

            $unavailable = $this->boolean('changed_paths_unavailable');
            $rawPaths = $this->input('changed_paths');
            if ($unavailable) {
                if (is_string($rawPaths) && trim($rawPaths) !== '') {
                    $validator->errors()->add('changed_paths', __('Choose either changed paths or unavailable path data, not both.'));
                }

                return;
            }

            if (! is_string($rawPaths) || trim($rawPaths) === '') {
                $validator->errors()->add('changed_paths', __('Enter at least one changed repository path.'));

                return;
            }

            $paths = preg_split('/\R/u', $rawPaths) ?: [];
            $normalized = [];
            foreach ($paths as $path) {
                $path = trim($path);
                if ($path === '') {
                    continue;
                }
                if (RepositoryPath::normalize($path) === null) {
                    $validator->errors()->add('changed_paths', __('Changed paths must be safe relative repository paths.'));

                    return;
                }

                $normalized[$path] = true;
            }

            if (count($normalized) > RepositoryPath::MAX_CHANGED_PATHS) {
                $validator->errors()->add('changed_paths', __('Enter no more than :count changed paths.', [
                    'count' => RepositoryPath::MAX_CHANGED_PATHS,
                ]));
            }
        }];
    }

    /**
     * Determine whether the request asks for a preview rather than the empty form state.
     */
    public function hasPreviewInput(): bool
    {
        return $this->has('changed_paths') || $this->boolean('changed_paths_unavailable');
    }

    /**
     * Return the normalized path set required by the pure evaluator.
     *
     * @return list<string>|null Normalized paths, or null when provider path data is unavailable.
     */
    public function changedPaths(): ?array
    {
        if ($this->boolean('changed_paths_unavailable')) {
            return null;
        }

        $rawPaths = $this->input('changed_paths');
        if (! is_string($rawPaths)) {
            return [];
        }

        $normalized = [];
        foreach (preg_split('/\R/u', $rawPaths) ?: [] as $path) {
            $path = RepositoryPath::normalize($path);
            if ($path !== null) {
                $normalized[$path] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * Preserve the textarea value for the GET form without exposing any repository secrets.
     */
    public function changedPathsInput(): string
    {
        return is_string($this->input('changed_paths')) ? $this->input('changed_paths') : '';
    }

    /**
     * Return the checkbox state used to reproduce the submitted preview mode.
     */
    public function pathsUnavailable(): bool
    {
        return $this->boolean('changed_paths_unavailable');
    }
}
