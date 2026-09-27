<?php

declare(strict_types=1);

namespace App\Data\Users;

use Carbon\CarbonImmutable;

final readonly class BrowserSession
{
    /**
     * One signed-in browser on the sessions list.
     *
     * @param  string  $id  The session ID, used to sign that browser out.
     * @param  string  $device  A readable browser and system, such as "Firefox on macOS".
     * @param  ?string  $ipAddress  The address the session was last seen from.
     * @param  CarbonImmutable  $lastActiveAt  When the session last made a request.
     * @param  bool  $current  Whether this is the browser looking at the page.
     */
    public function __construct(
        public string $id,
        public string $device,
        public ?string $ipAddress,
        public CarbonImmutable $lastActiveAt,
        public bool $current,
    ) {}
}
