<?php

namespace App\Services;

use App\Models\Organization;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalIncidentExporter
{
    public function __construct(private readonly OperationalIncidentQuery $incidents) {}

    /**
     * Stream current-workspace operational incidents as the existing private CSV evidence export.
     */
    public function stream(Organization $organization): StreamedResponse
    {
        $incidents = $this->incidents->forExport($organization)->get();

        return response()->streamDownload(function () use ($incidents): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Status', 'Severity', 'Category', 'Resource ID', 'Title', 'Owner', 'Occurrences', 'Detected UTC', 'Resolved UTC', 'Summary', 'Resolution']);
            foreach ($incidents as $incident) {
                fputcsv($out, array_map($this->csvCell(...), [$incident->id, $incident->status, $incident->severity, $incident->category, $incident->resource_id, $incident->title, $incident->assignee?->name, $incident->occurrences, $incident->detected_at?->utc()->toIso8601String(), $incident->resolved_at?->utc()->toIso8601String(), $incident->summary, $incident->resolution]));
            }
            fclose($out);
        }, 'operational-incidents.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * Preserve the operational export's existing formula-prefix behavior and null-to-empty conversion.
     */
    private function csvCell(mixed $value): string
    {
        return preg_match('/\A[=+\-@]/', (string) $value) ? "'".$value : (string) $value;
    }
}
