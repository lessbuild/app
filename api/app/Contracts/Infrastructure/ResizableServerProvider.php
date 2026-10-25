<?php

declare(strict_types=1);

namespace App\Contracts\Infrastructure;

/**
 * A cloud provider that can resize an existing server (change its plan). Resizing powers the server off, changes the
 * size, and powers it back on, so it means a short outage; growing the disk is permanent. Providers that can't do this
 * simply don't implement it, and the Resize option isn't offered for them.
 */
interface ResizableServerProvider
{
    /**
     * Power the server off and wait until it's off, so it can be resized.
     *
     * @param  int|string  $identifier  The server's ID at the provider.
     * @return void
     */
    public function powerOffServer(int|string $identifier): void;

    /**
     * Resize the server to a new size, optionally growing its disk (permanent), and wait for it to finish.
     *
     * @param  int|string  $identifier  The server's ID at the provider.
     * @param  string  $size  The new size slug from the provider's catalog.
     * @param  bool  $resizeDisk  Whether to also grow the disk (can't be undone), or change CPU and memory only.
     * @return void
     */
    public function resizeServer(int|string $identifier, string $size, bool $resizeDisk): void;

    /**
     * Power the server back on after a resize.
     *
     * @param  int|string  $identifier  The server's ID at the provider.
     * @return void
     */
    public function powerOnServer(int|string $identifier): void;
}
