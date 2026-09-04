<?php

namespace App\Http\Controllers;

use App\Models\SignInEvent;
use App\Services\ActivityRecorder;
use App\Services\ClientMetadata;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignInHistoryController extends Controller
{
    public function index(Request $request, ClientMetadata $clients): View
    {
        $filters = $this->filters($request);
        $signIns = $this->filteredSignIns($request, $filters)
            ->select(['id', 'method', 'ip_address', 'user_agent', 'signed_in_at'])
            ->orderByDesc('signed_in_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->appends(array_filter($filters, fn ($value) => $value !== null));
        $signIns->setCollection($signIns->getCollection()
            ->map(fn (SignInEvent $event): array => [
                'id' => $event->id,
                'method' => $event->methodName(),
                'device' => $clients->deviceName($event->user_agent),
                'ip_address' => $clients->displayIp($event->ip_address),
                'signed_in_at' => $event->signed_in_at,
            ]));

        return view('scenes.users.sign-ins', [
            'signIns' => $signIns,
            'filters' => $filters,
            'methods' => collect(SignInEvent::METHODS)
                ->mapWithKeys(fn (string $method): array => [$method => SignInEvent::methodLabel($method)]),
        ]);
    }

    public function export(Request $request, ClientMetadata $clients): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'lessbuild-sign-ins-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $clients, $filters): void {
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

            $this->filteredSignIns($request, $filters)
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

    /** @return array{method: ?string, date_from: ?string, date_to: ?string} */
    private function filters(Request $request): array
    {
        $method = $request->string('method')->toString();

        return [
            'method' => in_array($method, SignInEvent::METHODS, true) ? $method : null,
            'date_from' => $this->date($request->string('date_from')->toString()),
            'date_to' => $this->date($request->string('date_to')->toString()),
        ];
    }

    /** @param array{method: ?string, date_from: ?string, date_to: ?string} $filters */
    private function filteredSignIns(Request $request, array $filters): HasMany
    {
        return $request->user()->signIns()
            ->when($filters['method'], fn ($query, string $method) => $query
                ->where('method', $method))
            ->when($filters['date_from'], fn ($query, string $date) => $query
                ->whereDate('signed_in_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query
                ->whereDate('signed_in_at', '<=', $date));
    }

    private function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
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
