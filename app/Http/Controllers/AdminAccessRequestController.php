<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Notifications\AccessInvitationNotification;
use App\Services\AccessInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAccessRequestController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        $status = in_array($request->query('status'), AccessRequest::STATUSES, true) ? $request->query('status') : null;

        return view('admin.access-requests', [
            'requests' => AccessRequest::query()->with('reviewer:id,name')->when($status, fn ($query) => $query->where('status', $status))->latest()->paginate(25)->withQueryString(),
            'status' => $status,
            'counts' => AccessRequest::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status'),
        ]);
    }

    public function update(Request $request, AccessRequest $accessRequest, AccessInvitation $invitations): RedirectResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        $validated = $request->validate([
            'status' => ['required', Rule::in(AccessRequest::STATUSES)],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'resend_invitation' => ['nullable', 'boolean'],
        ]);
        abort_if($accessRequest->accepted_at !== null && $validated['status'] !== 'accepted', 422, __('Accepted requests are immutable onboarding records until retention removes them.'));
        abort_if($accessRequest->accepted_at === null && $validated['status'] === 'accepted', 422, __('Only successful invitation registration can accept a request.'));
        $previousStatus = $accessRequest->status;
        $accessRequest->update([
            'status' => $validated['status'],
            'review_notes' => $validated['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($validated['status'] === 'invited' && ($previousStatus !== 'invited' || ($validated['resend_invitation'] ?? false))) {
            $token = $invitations->issue($accessRequest);
            Notification::route('mail', $accessRequest->email)->notify(new AccessInvitationNotification(
                route('register', ['invite' => $token]),
                (int) config('lessbuild.registration.invitation_days', 7),
            ));
        } elseif ($validated['status'] !== 'invited' && $accessRequest->invitation_token_hash !== null) {
            $accessRequest->update(['invitation_token_hash' => null, 'invitation_expires_at' => null]);
        }

        return back()->with('success', __('Access request updated.'));
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
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

    private function csvCell(mixed $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', (string) $value);

        return preg_match('/^[=+\-@\t]/', $value) ? "'".$value : $value;
    }
}
