<?php

declare(strict_types=1);

namespace BuildPusher\Sdk;

use RuntimeException;

/** The API answered with an error status. */
final class ApiException extends RuntimeException
{
    /**
     * Create a new ApiException instance.
     *
     * @param  string  $message  What went wrong.
     * @param  int  $status  The HTTP status.
     * @param  array<string, mixed>  $body  The decoded error body, when there was one.
     */
    public function __construct(string $message, public readonly int $status, public readonly array $body = [])
    {
        parent::__construct($message, $status);
    }
}
