<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Resources\IngestReceiptResource;
use App\Modules\Monitor\Models\IngestReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngestReceiptController extends Controller
{
    public function show(Request $request, string $receipt): JsonResponse
    {
        $environment = $request->attributes->get('ingest_environment');
        $record = IngestReceipt::query()->whereBelongsTo($environment)->findOrFail($receipt);

        return (new IngestReceiptResource($record))->response()->header('Cache-Control', 'no-store, private');
    }
}
