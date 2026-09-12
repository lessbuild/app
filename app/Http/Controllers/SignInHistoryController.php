<?php

namespace App\Http\Controllers;

use App\Http\Requests\SignInHistoryIndexRequest;
use App\Models\SignInEvent;
use App\Services\ActivityRecorder;
use App\Services\ClientMetadata;
use App\Services\SignInHistoryExporter;
use App\Services\SignInHistoryQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignInHistoryController extends Controller
{
    public function __construct(
        private readonly SignInHistoryQuery $signIns,
        private readonly SignInHistoryExporter $exporter,
        private readonly ClientMetadata $clients,
    ) {}

    /**
     * Render the request user's filtered sign-in history with readable device/IP labels and matching summary metrics.
     */
    public function index(SignInHistoryIndexRequest $request): View
    {
        $filters = $request->filters();
        $signIns = $this->signIns->for($request->user(), $filters)
            ->select(['id', 'method', 'ip_address', 'user_agent', 'signed_in_at'])
            ->orderByDesc('signed_in_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->appends(array_filter($filters, fn ($value) => $value !== null));
        $signIns->setCollection($signIns->getCollection()
            ->map(fn (SignInEvent $event): array => [
                'id' => $event->id,
                'method' => $event->methodName(),
                'device' => $this->clients->deviceName($event->user_agent),
                'ip_address' => $this->clients->displayIp($event->ip_address),
                'signed_in_at' => $event->signed_in_at,
            ]));

        return view('scenes.users.sign-ins', [
            'signIns' => $signIns,
            'filters' => $filters,
            'metrics' => $this->signIns->metrics($request->user(), $filters),
            'methods' => collect(SignInEvent::METHODS)
                ->mapWithKeys(fn (string $method): array => [$method => SignInEvent::methodLabel($method)]),
        ]);
    }

    /**
     * Stream the request user's filtered sign-in history with readable client metadata as private, spreadsheet-safe CSV.
     */
    public function export(SignInHistoryIndexRequest $request): StreamedResponse
    {
        return $this->exporter->stream($request->user(), $request->filters());
    }

    /**
     * Validate the current password, delete the user's sign-in history atomically, and redirect with the deleted count.
     */
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
}
