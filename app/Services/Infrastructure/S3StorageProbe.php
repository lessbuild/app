<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\BackupDestination;
use App\Services\Storage\S3Client;
use App\Services\Storage\S3Location;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Proves a backup destination works: writes, reads back and deletes a small object with AWS Signature V4 requests. */
class S3StorageProbe
{
    /**
     * Create a new S3StorageProbe instance.
     *
     * Tests backup destinations.
     *
     * @param  S3Client  $s3  Makes the signed requests.
     */
    public function __construct(private readonly S3Client $s3) {}

    /**
     * Write a small test object, reads it back and compares it, then deletes it (a failed delete is reported but
     * doesn't fail the check). Throws with a message safe to show when anything fails.
     *
     * @param  BackupDestination  $destination
     * @return void
     *
     * @throws RuntimeException with a message safe to show (no response bodies)
     */
    public function check(BackupDestination $destination): void
    {
        $prefix = trim($destination->path_prefix, '/');
        if (preg_match('/\A[a-zA-Z0-9._\/-]+\z/D', $prefix) !== 1) {
            throw new RuntimeException('The backup path prefix is invalid.');
        }
        $key = "{$prefix}/connection-tests/".Str::uuid().'.bin';
        $payload = config('app.name').' backup destination check '.Str::uuid()."\n";
        $this->assertSuccessful('write', $this->request($destination, 'PUT', $key, $payload));
        try {
            $read = $this->request($destination, 'GET', $key);
            $this->assertSuccessful('read', $read);
            if ($read->body() !== $payload) {
                throw new RuntimeException('Reading the test object back returned different content.');
            }
        } finally {
            try {
                $this->assertSuccessful('delete', $this->request($destination, 'DELETE', $key));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * Send one signed S3 request for an object in the destination's bucket.
     *
     * @param  BackupDestination  $destination
     * @param  string  $method
     * @param  string  $key
     * @param  string  $body
     * @return Response
     */
    private function request(BackupDestination $destination, string $method, string $key, string $body = ''): Response
    {
        return $this->s3->request(new S3Location($destination->endpoint, $destination->region, $destination->bucket, $destination->access_key, $destination->secret_key), $method, $key, $body);
    }

    /**
     * Throw when a request failed, with the status and S3's error code but never the response body.
     *
     * @param  string  $operation
     * @param  Response  $response
     * @return void
     */
    private function assertSuccessful(string $operation, Response $response): void
    {
        $this->s3->assertSuccessful($operation, $response);
    }
}
