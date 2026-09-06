<?php

namespace App\Presenters;

use App\Models\Server;

final class ServerPresenter
{
    /**
     * @param  Server  $server  The server whose user-facing name is requested.
     * @return string|null Its nonempty display name, falling back to the provider name.
     */
    public static function label(Server $server): ?string
    {
        return is_string($server->display_name) && $server->display_name !== ''
            ? $server->display_name
            : $server->name;
    }
}
