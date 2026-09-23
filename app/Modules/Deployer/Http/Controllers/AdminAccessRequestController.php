<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\AccessRequest\ReviewAccessRequestAction;
use App\Modules\Deployer\Exceptions\AccessRequestReviewException;
use App\Modules\Deployer\Http\Requests\UpdateAccessRequestRequest;
use App\Modules\Deployer\Models\AccessRequest;
use App\Modules\Deployer\Support\CsvCell;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAccessRequestController extends Controller
{
    /**
     * Require platform administration and render status-filtered access requests with reviewer details and aggregate status counts.
     */
    public function index(Request $request): View
    {
        $this->authorize('platform-admin');
        $status = in_array($request->query('status'), AccessRequest::STATUSES, true) ? $request->query('status') : null;
        $requests = AccessRequest::query()
            ->with('reviewer:id,name')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();
        $dialog = $request->string('dialog')->toString();
        $dialogId = preg_match('/\Areview-access-request-(\d+)\z/D', $dialog, $matches) === 1
            ? (int) $matches[1]
            : null;
        $oldRequestId = filter_var(old('_access_request_review'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) ?: null;
        $editingRequestId = $dialogId ?? $oldRequestId;
        $editingRequest = $editingRequestId === null
            ? null
            : $requests->getCollection()->firstWhere('id', $editingRequestId)
                ?? AccessRequest::query()->with('reviewer:id,name')->find($editingRequestId);
        $reviewDialogId = $editingRequest?->id === null
            ? null
            : 'review-access-request-'.$editingRequest->id;

        return view('admin.access-requests', [
            'requests' => $requests,
            'status' => $status,
            'counts' => AccessRequest::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status'),
            'editingRequest' => $editingRequest,
            'reviewDialogId' => $reviewDialogId,
            'reviewDialogOpen' => $reviewDialogId !== null
                && (($dialog === $reviewDialogId && ! session()->has('success'))
                    || (old('_access_request_review') !== null
                        && (string) old('_access_request_review') === (string) $editingRequest->id)),
        ]);
    }

    /**
     * Validate an administrator's review decision and optional invitation resend while preserving accepted onboarding records.
     *
     * @return RedirectResponse The saved result after issuing or invalidating invitation credentials as needed.
     */
    public function update(UpdateAccessRequestRequest $request, AccessRequest $accessRequest, ReviewAccessRequestAction $review): RedirectResponse
    {
        try {
            $review->handle($accessRequest, $request->user(), [
                'status' => $request->validated('status'),
                'review_notes' => $request->validated('review_notes'),
                'resend_invitation' => (bool) ($request->validated('resend_invitation') ?? false),
            ]);
        } catch (AccessRequestReviewException $exception) {
            abort(422, $exception->getMessage());
        }

        return back()->with('success', __('Access request updated.'));
    }

    /**
     * Require platform administration and stream status-filtered applicant and review details as private, spreadsheet-safe CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('platform-admin');
        $status = in_array($request->query('status'), AccessRequest::STATUSES, true) ? $request->query('status') : null;

        return response()->streamDownload(function () use ($status): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Name', 'Email', 'Company', 'Team size', 'Plan', 'Status', 'Use case', 'Review notes', 'Requested at', 'Reviewed at', 'Accepted at']);
            AccessRequest::query()->when($status, fn ($query) => $query->where('status', $status))->oldest('id')->chunkById(100, function ($requests) use ($output): void {
                foreach ($requests as $lead) {
                    fputcsv($output, array_map([$this, 'csvCell'], [
                        $lead->name, $lead->email, $lead->company, $lead->team_size, $lead->plan, $lead->status,
                        $lead->use_case, $lead->review_notes, $lead->created_at?->toIso8601String(),
                        $lead->reviewed_at?->toIso8601String(), $lead->accepted_at?->toIso8601String(),
                    ]));
                }
            });
            fclose($output);
        }, 'buildpusher-access-requests-'.now()->format('Y-m-d').'.csv', ['Cache-Control' => 'no-store, private', 'Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Cast export values to text, flatten line breaks, and escape leading spreadsheet formula characters.
     */
    private function csvCell(mixed $value): string
    {
        return CsvCell::singleLine($value);
    }
}
