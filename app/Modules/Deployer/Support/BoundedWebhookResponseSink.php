<?php

namespace App\Modules\Deployer\Support;

/** A resource-backed Guzzle sink that aborts once the response body exceeds its configured byte limit. */
final class BoundedWebhookResponseSink
{
    private const SCHEME = 'buildpusher-alert-response';

    /** @var array<int, self> */
    private static array $streams = [];

    public mixed $context;

    /** @var resource|null */
    private $stream = null;

    private int $limit = 0;

    private int $written = 0;

    private bool $oversized = false;

    /** @return resource|null */
    public static function open(int $limit): mixed
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        $stream = @fopen(self::SCHEME.'://response', 'w+b');
        if (! is_resource($stream)) {
            return null;
        }

        $wrapper = stream_get_meta_data($stream)['wrapper_data'] ?? null;
        if (! $wrapper instanceof self) {
            fclose($stream);

            return null;
        }
        $wrapper->limit = max(0, $limit);
        self::$streams[(int) $stream] = $wrapper;

        return $stream;
    }

    /** @param resource $stream */
    public static function exceeded(mixed $stream): bool
    {
        return is_resource($stream) && (self::$streams[(int) $stream]->oversized ?? false);
    }

    /** @param resource|null $stream */
    public static function close(mixed $stream): void
    {
        if (! is_resource($stream)) {
            return;
        }

        $key = (int) $stream;
        fclose($stream);
        unset(self::$streams[$key]);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->stream = fopen('php://temp', 'w+b');

        return is_resource($this->stream);
    }

    public function stream_write(string $data): int
    {
        if ($this->written + strlen($data) > $this->limit) {
            $this->oversized = true;

            return 0;
        }

        $written = fwrite($this->stream, $data);
        $this->written += $written;

        return $written;
    }

    public function stream_read(int $count): string
    {
        return fread($this->stream, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->stream);
    }

    public function stream_tell(): int|false
    {
        return ftell($this->stream);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->stream, $offset, $whence) === 0;
    }

    /** @return array<string, int>|false */
    public function stream_stat(): array|false
    {
        return fstat($this->stream);
    }

    public function stream_flush(): bool
    {
        return fflush($this->stream);
    }

    public function stream_close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
