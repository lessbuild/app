<?php

namespace App\Http\Controllers;

use App\Actions\Account\ClearSignInHistoryAction;
use App\Http\Requests\ClearSignInHistoryRequest;
use App\Http\Requests\SignInHistoryIndexRequest;
use App\Models\SignInEvent;
use App\Services\ClientMetadata;
use App\Services\SignInHistoryExporter;
use App\Services\SignInHistoryQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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
        $isFragment = $request->string('fragment')->toString() === 'sign-in-history';
        $paginationQuery = array_filter($filters, fn ($value) => $value !== null);
        if ($isFragment) {
            $paginationQuery['fragment'] = 'sign-in-history';
        }

        $signIns = $this->signIns->for($request->user(), $filters)
            ->select(['id', 'method', 'ip_address', 'user_agent', 'signed_in_at'])
            ->orderByDesc('signed_in_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->appends($paginationQuery);
        $signIns->setCollection($signIns->getCollection()
            ->map(fn (SignInEvent $event): array => [
                'id' => $event->id,
                'method' => $event->methodName(),
                'device' => $this->clients->deviceName($event->user_agent),
                'ip_address' => $this->clients->displayIp($event->ip_address),
                'signed_in_at' => $event->signed_in_at,
            ]));

        $viewData = [
            'signIns' => $signIns,
            'filters' => $filters,
            'metrics' => $this->signIns->metrics($request->user(), $filters),
            'methods' => collect(SignInEvent::METHODS)
                ->mapWithKeys(fn (string $method): array => [$method => SignInEvent::methodLabel($method)]),
        ];

        if ($isFragment) {
            return view('components.scenes.users.sign-ins-content', [
                ...$viewData,
                'filterAction' => route('account.sign-ins.index', ['fragment' => 'sign-in-history']),
                'filterFragmentAction' => route('account.sign-ins.index', ['fragment' => 'sign-in-history']),
                'clearFiltersUrl' => route('account.sign-ins.index'),
                'filterIdPrefix' => 'sign-in-dialog-',
            ]);
        }

        return view('scenes.users.sign-ins', $viewData);
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
    public function destroy(ClearSignInHistoryRequest $request, ClearSignInHistoryAction $clear): RedirectResponse
    {
        $deleted = $clear->handle($request->user());

        return back()->with('sign_ins_status', $deleted > 0
            ? trans_choice(
                ':count sign-in record deleted.|:count sign-in records deleted.',
                $deleted,
                ['count' => $deleted],
            )
            : __('There was no sign-in history to clear.'));
    }
}
