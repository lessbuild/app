<?php

namespace App\Http\Controllers;

use App\Actions\AccessRequest\ReviewAccessRequestAction;
use App\Exceptions\AccessRequestReviewException;
use App\Http\Requests\UpdateAccessRequestRequest;
use App\Models\AccessRequest;
use App\Support\CsvCell;
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

        return view('admin.access-requests', [
            'requests' => AccessRequest::query()->with('reviewer:id,name')->when($status, fn ($query) => $query->where('status', $status))->latest()->paginate(25)->withQueryString(),
            'status' => $status,
            'counts' => AccessRequest::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status'),
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
