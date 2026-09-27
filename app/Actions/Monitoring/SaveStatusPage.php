<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class SaveStatusPage
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Create or change a status page and replace its components (in the order given) with the chosen monitors.
     *
     * @param  array<string, mixed>  $data  validated by StatusPageRequest
     */
    public function handle(Account $account, User $actor, array $data, ?StatusPage $page = null): StatusPage
    {
        return DB::transaction(function () use ($account, $actor, $data, $page): StatusPage {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize($page === null ? 'create' : 'update', $page ?? [StatusPage::class, $account]);
            $isNew = $page === null;
            $page = $isNew
                ? new StatusPage
                : StatusPage::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($page->id);
            $slug = $this->slug($page, $data);
            if (StatusPage::query()->where('slug', $slug)->when($page->exists, fn ($query) => $query->whereKeyNot($page->id))->exists()) {
                throw ValidationException::withMessages(['slug' => __('That public address is already taken.')]);
            }

            $monitorIds = array_values(array_unique(array_map('intval', is_array($data['monitor_ids'] ?? null) ? $data['monitor_ids'] : [])));
            $monitors = Monitor::query()->forAccount($account)->whereKey($monitorIds)->get()->keyBy('id');
            if ($monitors->count() !== count($monitorIds)) {
                throw ValidationException::withMessages(['monitor_ids' => __('Choose monitors from this account.')]);
            }

            $description = trim((string) ($data['description'] ?? ''));
            $page->forceFill([
                'account_id' => $account->id,
                'created_by' => $page->created_by ?? $actor->id,
                'name' => trim((string) $data['name']),
                'slug' => $slug,
                'description' => $description !== '' ? $description : null,
                'published' => (bool) ($data['published'] ?? false),
            ])->save();

            $labels = $page->components()->pluck('label', 'monitor_id');
            $page->components()->delete();
            foreach ($monitorIds as $position => $monitorId) {
                $monitor = $monitors->get($monitorId) ?? throw new LogicException('Checked above.');
                $page->components()->create(['monitor_id' => $monitorId, 'label' => $labels->get($monitorId, $monitor->name), 'position' => $position]);
            }

            $this->audit->handle($isNew ? AuditAction::StatusPageCreated : AuditAction::StatusPageUpdated, $actor, $account->id, [
                'page' => $page->name, 'slug' => $page->slug, 'published' => $page->published, 'components' => count($monitorIds),
            ]);

            return $page;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    private function slug(StatusPage $page, array $data): string
    {
        $requested = Str::slug((string) ($data['slug'] ?? ''));
        if ($requested !== '') {
            return $requested;
        }
        if ($page->exists) {
            return $page->slug;
        }

        return trim(Str::limit(Str::slug((string) $data['name']), 90, '').'-'.Str::lower(Str::random(6)), '-');
    }
}
