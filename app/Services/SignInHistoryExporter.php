<?php

namespace App\Services;

use App\Models\SignInEvent;
use App\Models\User;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignInHistoryExporter
{
    public function __construct(
        private readonly SignInHistoryQuery $signIns,
        private readonly ClientMetadata $clients,
    ) {}

    /**
     * Stream the request user's filtered sign-in metadata as private, spreadsheet-safe CSV.
     *
     * @param  array{method: ?string, date_from: ?string, date_to: ?string}  $filters  Validated history filters.
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-sign-ins-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Sign-in ID',
                'Method',
                'Browser and device',
                'IP address',
                'Signed in at',
            ], ',', '"', '');

            $this->signIns->for($user, $filters)
                ->select(['id', 'method', 'ip_address', 'user_agent', 'signed_in_at'])
                ->orderByDesc('signed_in_at')
                ->orderByDesc('id')
                ->lazy(250)
                ->each(function (SignInEvent $event) use ($output): void {
                    fputcsv($output, [
                        $event->id,
                        $this->csvCell($event->methodName()),
                        $this->csvCell($this->clients->deviceName($event->user_agent)),
                        $this->csvCell($this->clients->displayIp($event->ip_address)),
                        $event->signed_in_at->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Escape derived sign-in export text that could be interpreted as a spreadsheet formula. */
    private function csvCell(string $value): string
    {
        return CsvCell::escape($value);
    }
}
