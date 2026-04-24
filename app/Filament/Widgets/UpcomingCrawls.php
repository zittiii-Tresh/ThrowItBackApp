<?php

namespace App\Filament\Widgets;

use App\Models\Site;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Dashboard panel — "Upcoming today" table. Shows every active site
 * whose next_run_at falls within the current calendar day.
 */
class UpcomingCrawls extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Upcoming today';

    public function table(Table $table): Table
    {
        return $table
            ->query(Site::active()
                ->whereNotNull('next_run_at')
                ->whereBetween('next_run_at', [now()->startOfDay(), now()->endOfDay()])
                ->orderBy('next_run_at'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Site')
                    ->weight('semibold')
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('next_run_at')
                    ->label('Scheduled at')
                    ->dateTime('H:i')
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('frequency')
                    ->label('Frequency')
                    ->state(fn (Site $s) => $s->describeFrequency())
                    ->badge()
                    ->color('primary')
                    ->alignment('center'),
            ])
            ->emptyStateHeading('Nothing scheduled for today')
            ->emptyStateDescription('Active sites will appear here when their next run falls on this date.')
            ->emptyStateIcon('heroicon-o-calendar')
            ->paginated(false);
    }
}
