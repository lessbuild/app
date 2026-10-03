<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\AnalyticsSite;
use App\Support\Analytics\SharedReportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class UnlockSharedReportController
{
    /**
     * Check the password of a shared report and remember it for this session.
     *
     * @param  Request  $request
     * @param  string  $token
     * @return RedirectResponse
     */
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $site = AnalyticsSite::query()->where('share_token', $token)->firstOrFail();
        $password = $request->validate(['password' => ['required', 'string', 'max:255']])['password'];
        if ($site->share_password !== null && ! Hash::check($password, $site->share_password)) {
            throw ValidationException::withMessages(['password' => __('That password isn’t right.')]);
        }
        $request->session()->put(SharedReportAccess::sessionKey($site), SharedReportAccess::sessionProof($site));

        return to_route('analytics.shared', $token);
    }
}
