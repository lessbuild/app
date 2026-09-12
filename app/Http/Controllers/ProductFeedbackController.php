<?php

namespace App\Http\Controllers;

use App\Actions\ProductFeedback\CreateProductFeedbackAction;
use App\Actions\ProductFeedback\DeleteProductFeedbackAction;
use App\Actions\ProductFeedback\UpdateProductFeedbackAction;
use App\Http\Requests\StoreProductFeedbackRequest;
use App\Http\Requests\UpdateProductFeedbackRequest;
use App\Models\ProductFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductFeedbackController extends Controller
{
    /**
     * Render status/category-filtered workspace feedback, restricting non-managers to their own submissions.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;
        $canReview = $request->user()->can('reviewAny', ProductFeedback::class);
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
    public function store(StoreProductFeedbackRequest $request, CreateProductFeedbackAction $createFeedback): RedirectResponse
    {
        $createFeedback->handle($request->user()->currentOrganization, $request->user(), $request->validated());

        return back()->with('success', __('Feedback submitted privately to your workspace.'));
    }

    /**
     * Require workspace management access and save the review status and response before redirecting back.
     */
    public function update(UpdateProductFeedbackRequest $request, ProductFeedback $feedback, UpdateProductFeedbackAction $updateFeedback): RedirectResponse
    {
        $updateFeedback->handle($feedback, $request->user(), $request->validated());

        return back()->with('success', __('Feedback review updated.'));
    }

    /**
     * Delete current-workspace feedback belonging to the user or managed by them, then redirect back.
     */
    public function destroy(Request $request, ProductFeedback $feedback, DeleteProductFeedbackAction $deleteFeedback): RedirectResponse
    {
        $this->authorize('delete', $feedback);
        $deleteFeedback->handle($feedback);

        return back()->with('success', __('Feedback removed.'));
    }
}
