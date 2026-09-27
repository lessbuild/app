<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use App\Models\TelemetryEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Case-sensitive "contains" search over an event's labels and the text inside its stored payload. */
final class EventTextSearch
{
    /** @param Builder<TelemetryEvent> $query */
    public static function apply(Builder $query, string $text): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
        // The message, body and indexed name inside the stored payload, per database.
        $payloadText = DB::getDriverName() === 'pgsql'
            ? ["payload->>'message'", "payload->>'body'", "payload#>>'{record,body,stringValue}'", "payload#>>'{_beacon,indexed_fields,name}'"]
            : ["json_extract(payload, '$.message')", "json_extract(payload, '$.body')", "json_extract(payload, '$.record.body.stringValue')", "json_extract(payload, '$._beacon.indexed_fields.name')"];
        $query->where(function (Builder $search) use ($pattern, $payloadText): void {
            foreach (['name', 'route', 'service', 'trace_id', 'span_id', ...$payloadText] as $column) {
                $search->whereRaw($column." LIKE ? ESCAPE '!'", [$pattern], 'or');
            }
        });
    }
}
