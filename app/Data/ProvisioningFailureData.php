<?php

namespace App\Data;

class ProvisioningFailureData
{
    public function __construct(
        public readonly string $message,
        public readonly ?int $exitCode,
    ) {}

    /** Return the message format persisted by server and website callbacks. */
    public function formattedMessage(): string
    {
        return $this->exitCode === null
            ? $this->message
            : "{$this->message} (exit code {$this->exitCode})";
    }
}
