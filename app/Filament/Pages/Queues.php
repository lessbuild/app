<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Admin\ForgetFailedJobs;
use App\Actions\Admin\RetryFailedJobs;
use App\Filament\Support\CurrentAdmin;
use App\Services\Admin\SystemHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use UnitEnum;

/** The queues, and the latest 100 failed jobs to retry or delete. Each retry or delete goes in the admin trail. */
final class Queues extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * The page's Blade view.
     *
     * @var string
     */
    protected string $view = 'filament.pages.queues';

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    /**
     * The sidebar group.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    /**
     * The position in the group.
     *
     * @var int|null
     */
    protected static ?int $navigationSort = 20;

    /**
     * Show how many jobs have failed in the sidebar.
     *
     * @return string|null
     */
    public static function getNavigationBadge(): ?string
    {
        $failed = count(app(FailedJobProviderInterface::class)->all());

        return $failed > 0 ? (string) $failed : null;
    }

    /**
     * Build the failed jobs table: the latest 100, each with Retry and Delete.
     *
     * @param  Table  $table
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Failed jobs'))
            ->records(fn (): array => self::failedJobs())
            ->emptyStateHeading(__('No failed jobs'))
            ->columns([
                TextColumn::make('job')->label(__('Job'))->description(fn (array $record): string => $record['error'])->wrap(),
                TextColumn::make('queue')->label(__('Queue'))->fontFamily('mono'),
                TextColumn::make('failed_at')->label(__('Failed'))->since(),
            ])
            ->recordActions([
                Action::make('retry')->label(__('Retry'))->icon(Heroicon::OutlinedArrowPath)
                    ->action(fn (array $record) => $this->retry((string) $record['uuid'])),
                Action::make('forget')->label(__('Delete'))->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()
                    ->action(fn (array $record) => $this->forget((string) $record['uuid'])),
            ]);
    }

    /**
     * Get the header's buttons: retry or delete every failed job.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $none = fn (): bool => count(app(FailedJobProviderInterface::class)->all()) === 0;

        return [
            Action::make('retryAll')->label(__('Retry all'))->icon(Heroicon::OutlinedArrowPath)->color('gray')->hidden($none)->requiresConfirmation()
                ->action(fn () => $this->retry(null)),
            Action::make('forgetAll')->label(__('Delete all'))->icon(Heroicon::OutlinedTrash)->color('danger')->hidden($none)->requiresConfirmation()
                ->action(fn () => $this->forget(null)),
        ];
    }

    /**
     * Give the view the queues.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['queues' => app(SystemHealth::class)->queues()];
    }

    /**
     * Retry one failed job, or all of them.
     *
     * @param  string|null  $uuid
     * @return void
     */
    private function retry(?string $uuid): void
    {
        app(RetryFailedJobs::class)->handle(CurrentAdmin::user(), $uuid);
        Notification::make()->success()->title($uuid === null ? __('Every failed job was queued again.') : __('The job was queued again.'))->send();
    }

    /**
     * Delete one failed job, or all of them.
     *
     * @param  string|null  $uuid
     * @return void
     */
    private function forget(?string $uuid): void
    {
        app(ForgetFailedJobs::class)->handle(CurrentAdmin::user(), $uuid);
        Notification::make()->success()->title($uuid === null ? __('Every failed job was deleted.') : __('The failed job was deleted.'))->send();
    }

    /**
     * Get the latest 100 failed jobs, keyed by their UUID.
     *
     * @return array<string, array{uuid: string, queue: string, job: string, error: string, failed_at: string}>
     */
    private static function failedJobs(): array
    {
        $jobs = [];
        foreach (array_slice(app(FailedJobProviderInterface::class)->all(), 0, 100) as $job) {
            $payload = json_decode((string) ($job->payload ?? ''), true);
            $uuid = (string) ($job->uuid ?? $job->id ?? '');
            $jobs[$uuid] = [
                'uuid' => $uuid,
                'queue' => (string) ($job->queue ?? ''),
                'job' => is_array($payload) ? (string) ($payload['displayName'] ?? $payload['job'] ?? '') : '',
                'error' => mb_substr(strtok((string) ($job->exception ?? ''), "\n") ?: '', 0, 300),
                'failed_at' => (string) ($job->failed_at ?? ''),
            ];
        }

        return $jobs;
    }
}
