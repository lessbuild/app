<?php

namespace App\Services;

use App\Contracts\ServerTroubleshootingConnection;
use App\Contracts\ServerTroubleshootingTransport;
use App\Data\ServerTroubleshootingTerminalSize;
use App\Exceptions\ServerTroubleshootingTransportException;
use App\Models\Server;
use Symfony\Component\Process\InputStream;
use Throwable;

class SshServerTroubleshootingTransport implements ServerTroubleshootingTransport
{
    public function __construct(private readonly Runner $runner) {}

    /**
     * Create a pinned, non-password SSH PTY owned by the future broker.
     *
     * No caller may use this adapter for an unpinned or incomplete server;
     * existing one-shot command compatibility remains in Runner::create().
     */
    public function connect(Server $server, ServerTroubleshootingTerminalSize $size): ServerTroubleshootingConnection
    {
        if ($server->provisioning_status !== Server::STATUS_ACTIVE) {
            throw new ServerTroubleshootingTransportException('The server is not active for troubleshooting.');
        }

        if (! $server->ssh_host_key) {
            throw new ServerTroubleshootingTransportException('A pinned SSH host identity is required for troubleshooting.');
        }

        if (! $server->public_ip || ! $server->ssh_private_key) {
            throw new ServerTroubleshootingTransportException('The server has incomplete troubleshooting connection details.');
        }

        $ssh = null;

        try {
            $ssh = $this->runner->server($server)->create(false);
            $input = new InputStream;
            $process = $ssh->interactiveProcess($input, $size);
            $connection = new ProcessServerTroubleshootingConnection(
                $process,
                $input,
                max(1, (int) config('lessbuild.troubleshooting.input_max_bytes', 8192)),
                max(1, (int) config('lessbuild.troubleshooting.output_frame_max_bytes', 16384)),
                $ssh->close(...),
            );
            $connection->start();

            return $connection;
        } catch (ServerTroubleshootingTransportException $exception) {
            $ssh?->close();

            throw $exception;
        } catch (Throwable $exception) {
            $ssh?->close();

            throw new ServerTroubleshootingTransportException(
                'Unable to open the troubleshooting connection.',
                previous: $exception,
            );
        }
    }
}
