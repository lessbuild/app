<?php

namespace App\Services;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;

class BuildDeploymentLogCallbackValidator
{
    public function __construct(private readonly ValidationFactory $validation) {}

    /**
     * Validate one deployment log payload at the callback's lock-safe execution point.
     *
     * @param  mixed  $log  Raw log input retained until the active-build check completes.
     * @return string The bounded validated log output.
     */
    public function validate(mixed $log): string
    {
        $data = $this->validation->validate(
            ['log' => $log],
            ['log' => ['required', 'string', 'max:'.max(1, (int) config('lessbuild.deployment_log_max_characters'))]],
        );

        return (string) $data['log'];
    }
}
