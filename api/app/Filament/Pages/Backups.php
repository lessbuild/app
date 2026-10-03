<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\CurrentAdmin;
use App\Models\PlatformBackup;
use App\Services\Admin\PlatformAdmins;
use App\Services\Admin\PlatformBackups;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use UnitEnum;

/** Copies of the platform's own database, taken every night at 02:30 UTC, and a button to take one now. */
final class Backups extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * The page's Blade view.
     *
     * @var string
     */
    protected string $view = 'filament.pages.backups';

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

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
    protected static ?int $navigationSort = 30;

    /**
     * Say when backups run and when the last good one was taken.
     *
     * @return string
     */
    public function getSubheading(): string
    {
        $latest = app(PlatformBackups::class)->latestSuccessful();

        return __('Taken every night at 02:30 UTC.').' '.($latest ? __('Last good backup :when.', ['when' => $latest->created_at?->diffForHumans()]) : __('No backup yet.'));
    }

    /**
     * Build the backups table, newest first.
     *
     * @param  Table  $table
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Recent backups'))
            ->query(fn (): Builder => PlatformBackup::query()->latest('id'))
            ->emptyStateHeading(__('No backups yet'))
            ->emptyStateDescription(__('The first runs tonight, or back up now.'))
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->since()->description(fn (PlatformBackup $record): string => __(ucfirst($record->trigger))),
                TextColumn::make('file')->label(__('File'))->fontFamily('mono')->wrap(),
                TextColumn::make('size')->label(__('Size'))->formatStateUsing(fn (?int $state): string => $state !== null ? Number::fileSize($state, 1) : '—'),
                TextColumn::make('kept')->label(__('Kept'))->state(fn (PlatformBackup $record): string => collect([$record->isLocal() ? __('this server') : null, $record->isOffsite() ? __('off-site') : null])->filter()->implode(' + ') ?: '—'),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (string $state): string => $state === 'succeeded' ? 'success' : 'danger')
                    ->description(fn (PlatformBackup $record): ?string => $record->error),
            ]);
    }

    /**
     * Get the header's button: back up now (recorded in the admin trail).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('backup')->label(__('Back up now'))->icon(Heroicon::OutlinedCircleStack)->requiresConfirmation()
                ->action(function (): void {
                    $backup = app(PlatformBackups::class)->create('manual');
                    app(PlatformAdmins::class)->record(CurrentAdmin::user(), 'backup.created', $backup->succeeded() ? "Backed up the database to {$backup->file}" : 'A database backup failed');
                    $backup->succeeded()
                        ? Notification::make()->success()->title($backup->isOffsite() ? __('Backed up and copied off-site.') : __('Backed up on this server.'))->body($backup->error)->send()
                        : Notification::make()->danger()->title(__('The backup failed: :error', ['error' => $backup->error]))->send();
                }),
        ];
    }

    /**
     * Give the view whether backups are copied off-site.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['offsite' => app(PlatformBackups::class)->offsiteConfigured()];
    }
}
