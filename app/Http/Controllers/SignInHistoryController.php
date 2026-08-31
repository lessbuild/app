<?php

namespace App\Http\Controllers;

use App\Models\SignInEvent;
use App\Services\ActivityRecorder;
use App\Services\ClientMetadata;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignInHistoryController extends Controller
{
    public function export(Request $request, ClientMetadata $clients): StreamedResponse
    {
        $filename = 'lessbuild-sign-ins-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $clients): void {
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

            $request->user()
                ->signIns()
                ->select(['id', 'method', 'ip_address', 'user_agent', 'signed_in_at'])
                ->orderByDesc('signed_in_at')
                ->orderByDesc('id')
                ->lazy(250)
                ->each(function (SignInEvent $event) use ($output, $clients): void {
                    fputcsv($output, [
                        $event->id,
                        $this->csvCell($event->methodName()),
                        $this->csvCell($clients->deviceName($event->user_agent)),
                        $this->csvCell($clients->displayIp($event->ip_address)),
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

    public function destroy(Request $request, ActivityRecorder $activity): RedirectResponse
    {
        $request->validateWithBag('signIns', [
            'current_password' => ['required', 'current_password'],
        ]);

        $deleted = DB::transaction(function () use ($request, $activity): int {
            $deleted = $request->user()->signIns()->delete();

            if ($deleted > 0) {
                $activity->recordAccount($request->user(), 'Successful sign-in history was cleared.');
            }

            return $deleted;
        });

        return back()->with('sign_ins_status', $deleted > 0
            ? trans_choice(
                ':count sign-in record deleted.|:count sign-in records deleted.',
                $deleted,
                ['count' => $deleted],
            )
            : __('There was no sign-in history to clear.'));
    }

    private function csvCell(string $value): string
    {
        $value = str_replace("\0", '', $value);

        return preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
