<?php

namespace App\Http\Controllers;

use App\Models\ProductFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductFeedbackController extends Controller
{
    /**
     * Render status/category-filtered workspace feedback, restricting non-managers to their own submissions.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;
        $canReview = $organization->permits($request->user(), 'manage');
        $status = in_array($request->query('status'), ProductFeedback::STATUSES, true) ? $request->query('status') : null;
        $category = in_array($request->query('category'), ProductFeedback::CATEGORIES, true) ? $request->query('category') : null;
        $feedback = ProductFeedback::query()->where('organization_id', $organization->id)
            ->when(! $canReview, fn ($query) => $query->where('user_id', $request->user()->id))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($category, fn ($query) => $query->where('category', $category))
            ->with(['submitter:id,name', 'reviewer:id,name'])->latest()->paginate(20)->withQueryString();

        return view('feedback.index', compact('feedback', 'canReview', 'status', 'category'));
    }

    /**
     * Validate feedback category, severity, description, and optional reproduction context, then save it privately.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(ProductFeedback::CATEGORIES)],
            'severity' => ['required', Rule::in(ProductFeedback::SEVERITIES)],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:10000'],
            'reproduction_steps' => ['nullable', 'string', 'max:10000'],
            'page' => ['nullable', 'string', 'max:500', 'regex:/\A\/(?!\/)[^?#\r\n]*\z/'],
        ]);
        $request->user()->currentOrganization->productFeedback()->create([...$data, 'user_id' => $request->user()->id, 'status' => 'open']);

        return back()->with('success', __('Feedback submitted privately to your workspace.'));
    }

    /**
     * Require workspace management access and save the review status and response before redirecting back.
     */
    public function update(Request $request, ProductFeedback $feedback): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($feedback->organization_id === $organization->id && $organization->permits($request->user(), 'manage'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(ProductFeedback::STATUSES)], 'review_response' => ['nullable', 'string', 'max:10000']]);
        $feedback->update([...$data, 'reviewed_by' => $request->user()->id, 'resolved_at' => in_array($data['status'], ['resolved', 'closed'], true) ? ($feedback->resolved_at ?? now()) : null]);

        return back()->with('success', __('Feedback review updated.'));
    }

    /**
     * Delete current-workspace feedback belonging to the user or managed by them, then redirect back.
     */
    public function destroy(Request $request, ProductFeedback $feedback): RedirectResponse
    {
        abort_unless($feedback->organization_id === $request->user()->current_organization_id
            && ($feedback->user_id === $request->user()->id || $feedback->organization->permits($request->user(), 'manage')), 403);
        $feedback->delete();

        return back()->with('success', __('Feedback removed.'));
    }
}
