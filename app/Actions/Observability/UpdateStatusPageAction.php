<?php

namespace App\Actions\Observability;

use App\Models\StatusPage;
use App\Services\Entitlements;
use Illuminate\Support\Facades\DB;

class UpdateStatusPageAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Update status-page details and website membership in one transaction.
     *
     * @param  array<string, mixed>  $attributes  Validated update fields and website IDs.
     */
    public function handle(StatusPage $page, array $attributes): StatusPage
    {
        $this->entitlements->enforce($page->organization, 'status_pages');

        return DB::transaction(function () use ($page, $attributes): StatusPage {
            $page->update([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'is_published' => $attributes['is_published'],
            ]);
            $page->websites()->sync($attributes['website_ids']);

            return $page;
        });
    }
}
