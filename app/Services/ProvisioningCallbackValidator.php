<?php

namespace App\Services;

use App\Data\ProvisioningFailureData;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

class ProvisioningCallbackValidator
{
    public function __construct(private readonly ValidationFactory $validation) {}

    /**
     * Validate one provisioning status at the callback's lock-safe execution point.
     *
     * @param  mixed  $status  Raw status input retained until lifecycle acceptance completes.
     * @param  int  $finalStage  Maximum stage from the current resource plan.
     * @return int The validated status as an integer.
     */
    public function status(mixed $status, int $finalStage): int
    {
        $data = $this->validation->validate(
            ['status' => $status],
            ['status' => ['required', 'integer', 'min:0', 'max:'.$finalStage]],
        );

        return (int) $data['status'];
    }

    /**
     * Validate one provisioning failure at the callback's lock-safe execution point.
     *
     * @param  mixed  $exitCode  Raw optional remote process exit code.
     * @param  mixed  $message  Raw remote failure message.
     * @return ProvisioningFailureData The typed validated failure details.
     */
    public function failure(mixed $exitCode, mixed $message): ProvisioningFailureData
    {
        $data = $this->validation->validate(
            ['exit_code' => $exitCode, 'message' => $message],
            [
                'exit_code' => ['nullable', 'integer'],
                'message' => ['required', 'string', 'max:2000'],
            ],
        );

        $validatedExitCode = $data['exit_code'] ?? null;

        return new ProvisioningFailureData(
            (string) $data['message'],
            $validatedExitCode === null ? null : (int) $validatedExitCode,
        );
    }

    /**
     * Validate one provisioning log at the callback's lock-safe execution point.
     *
     * @param  mixed  $log  Raw log input retained until attempt acceptance completes.
     * @param  int  $maxCharacters  Maximum configured log length for the resource.
     * @return string The bounded validated log output.
     */
    public function log(mixed $log, int $maxCharacters): string
    {
        $data = $this->validation->validate(
            ['log' => $log],
            ['log' => ['required', 'string', 'max:'.max(1, $maxCharacters)]],
        );

        return (string) $data['log'];
    }
}
