<?php

namespace App\Modules\Analytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection('analytics')->getPdo();
            Cache::store()->get('analytics-readiness');
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['status' => 'not_ready'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
