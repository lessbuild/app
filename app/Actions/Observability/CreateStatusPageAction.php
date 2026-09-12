<?php

namespace App\Actions\Observability;

use App\Models\Organization;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateStatusPageAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Create a uniquely slugged status page and attach its workspace websites atomically.
     *
     * @param  Organization  $organization  Workspace that owns the status page.
     * @param  User  $actor  Account recorded as the page creator.
     * @param  array<string, mixed>  $attributes  Validated page fields and website IDs.
     */
    public function handle(Organization $organization, User $actor, array $attributes): StatusPage
    {
        $this->entitlements->enforce($organization, 'status_pages');
        $base = Str::slug($attributes['slug'] ?: $attributes['name']) ?: 'status';
        $slug = $base;
        while (StatusPage::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return DB::transaction(function () use ($organization, $actor, $attributes, $slug): StatusPage {
            $page = $organization->statusPages()->create([
                'created_by' => $actor->id,
                'name' => $attributes['name'],
                'slug' => $slug,
                'description' => $attributes['description'] ?? null,
                'is_published' => $attributes['is_published'],
            ]);
            $page->websites()->sync($attributes['website_ids']);

            return $page;
        });
    }
}
