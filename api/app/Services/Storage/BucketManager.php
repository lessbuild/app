<?php

declare(strict_types=1);

namespace App\Services\Storage;

use RuntimeException;

/** Creates buckets and checks that keys can reach one, over the S3 API. */
final class BucketManager
{
    /**
     * Create a new BucketManager instance.
     *
     * @param  S3Client  $s3  Signs and sends the requests.
     */
    public function __construct(private readonly S3Client $s3) {}

    /**
     * Create the bucket (a bucket these keys already own counts as created). Amazon S3 outside us-east-1 is told the
     * region.
     *
     * @param  S3Location  $location
     * @param  bool  $amazon  whether it's Amazon S3
     * @return void
     *
     * @throws RuntimeException
     */
    public function create(S3Location $location, bool $amazon): void
    {
        $body = $amazon && ! in_array($location->region, ['', 'us-east-1'], true)
            ? '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>'.htmlspecialchars($location->region, ENT_XML1).'</LocationConstraint></CreateBucketConfiguration>'
            : '';
        $response = $this->s3->request($location, 'PUT', '', $body, 30);
        if ($response->status() === 409 && str_contains($response->body(), 'BucketAlreadyOwnedByYou')) {
            return;
        }
        $this->s3->assertSuccessful('create bucket', $response);
    }

    /**
     * Check that the keys can reach the bucket.
     *
     * @param  S3Location  $location
     * @return void
     *
     * @throws RuntimeException
     */
    public function check(S3Location $location): void
    {
        $this->s3->assertSuccessful('bucket check', $this->s3->request($location, 'HEAD', ''));
    }
}
