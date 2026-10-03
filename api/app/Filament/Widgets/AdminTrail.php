<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\PlatformAdminEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** The admin trail: admin rights granted or revoked, customers looked up, and every change made in the panel. */
final class AdminTrail extends TableWidget
{
    /**
     * Rendered with the page rather than after it, since it's quick.
     *
     * @var bool
     */
    protected static bool $isLazy = false;

    /**
     * Where it sits on the dashboard.
     *
     * @var int|null
     */
    protected static ?int $sort = 2;

    /**
     * It spans the dashboard's width.
     *
     * @var int|string|array<string, int|null>
     */
    protected int|string|array $columnSpan = 'full';

    /**
     * Build the trail table, newest first.
     *
     * @param  Table  $table
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Admin trail'))
            ->description(__('Everything changed here, and every customer looked up, is recorded.'))
            ->query(fn (): Builder => PlatformAdminEvent::query()->with('actor')->latest('id'))
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('description')->label(__('What happened'))->wrap(),
                TextColumn::make('action')->label(__('Action'))->badge()->color('gray'),
                TextColumn::make('actor.name')->label(__('Who'))->placeholder(__('Command line')),
                TextColumn::make('created_at')->label(__('When'))->since(),
            ]);
    }
}
