<?php

namespace App\Filament\Widgets;

use App\Enums\CrawlStatus;
use App\Models\CrawlRun;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Dashboard panel — "Recent crawl runs" table. Mirrors the mockup columns:
 * Site / Started / Pages / Status / Duration. Limited to the 5 most recent.
 */
class RecentCrawlRuns extends TableWidget
{
    protected static ?int $sort = 2;

    // Full row width on desktop — sits above the smaller "Upcoming today" widget.
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Recent crawl runs';

    public function table(Table $table): Table
    {
        return $table
            ->query(CrawlRun::query()
                ->with('site')
                ->latest()
                ->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('site.name')
                    ->label('Site')
                    ->weight('semibold')
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->since()
                    ->placeholder('—')
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('pages_crawled')
                    ->label('Pages')
                    ->numeric()
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CrawlStatus $state) => $state->label())
                    ->color(fn (CrawlStatus $state) => $state->color())
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (CrawlRun $r) => $r->durationHuman())
                    ->alignment('center'),
            ])
            ->paginated(false);
    }
}
