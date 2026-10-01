<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

/** Code shown to people receiving webhooks (kept in PHP so Blade doesn't read its braces). */
final class WebhookExamples
{
    /**
     * How to check a delivery's signature in PHP.
     *
     * @var string
     */
    public const PHP = <<<'PHP'
        $body = file_get_contents('php://input');
        $timestamp = $_SERVER['HTTP_X_BUILDPUSHER_TIMESTAMP'] ?? '';
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, getenv('BUILDPUSHER_WEBHOOK_SECRET'));

        if (! hash_equals($expected, $_SERVER['HTTP_X_BUILDPUSHER_SIGNATURE'] ?? '') || abs(time() - (int) $timestamp) > 300) {
            http_response_code(401);
            exit;
        }

        $event = json_decode($body, true); // ['id' => …, 'event' => 'deploy.succeeded', 'data' => [...]]
        PHP;
}
