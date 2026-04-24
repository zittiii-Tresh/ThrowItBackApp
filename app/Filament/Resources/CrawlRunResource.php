<?php

namespace App\Filament\Resources;

use App\Enums\CrawlStatus;
use App\Enums\TriggerSource;
use App\Filament\Resources\CrawlRunResource\Pages;
use App\Models\CrawlRun;
use App\Models\Site;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin Screen 4 — Crawl History.
 *
 * A read-only log of every crawl run across all sites. Columns match the
 * PDF mockup: Site / Started / Pages / Assets / Status / Triggered / Duration.
 *
 * Not a normal CRUD resource — crawl runs are created by the job engine,
 * not by admins. Hence no create/edit forms, no bulk delete.
 */
class CrawlRunResource extends Resource
{
    protected static ?string $model = CrawlRun::class;

    protected static ?string $navigationIcon  = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Crawl history';
    protected static ?int    $navigationSort  = 20;

    protected static ?string $modelLabel       = 'crawl run';
    protected static ?string $pluralModelLabel = 'crawl runs';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // Eager-load `site` so the status column blade (which reads
            // $run->site->max_pages for the progress bar baseline) and
            // the `site.name` text column don't hit N+1.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('site:id,name,max_pages'))
            ->columns([
                // Every column is centered so headers + values sit under each
                // other consistently — per user request. Numeric and text
                // columns alike use ->alignment('center').
                Tables\Columns\TextColumn::make('site.name')
                    ->label('Site')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable()
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('M j, H:i')
                    ->sortable()
                    ->placeholder('—')
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('pages_crawled')
                    ->label('Pages')
                    ->numeric()
                    ->alignment('center')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('assets_downloaded')
                    ->label('Assets')
                    ->numeric()
                    ->alignment('center')
                    ->toggleable(),

                // Live status: spinner + progress bar while Running, regular
                // colored badge otherwise. Same Blade view used on the sites
                // list — but here passes the CrawlRun as $getRecord().
                Tables\Columns\ViewColumn::make('liveStatus')
                    ->label('Status')
                    ->view('filament.columns.crawl-run-status')
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('triggered_by')
                    ->label('Triggered')
                    ->formatStateUsing(fn (TriggerSource $state) => $state->label())
                    ->alignment('center')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (CrawlRun $r): string => $r->durationHuman())
                    ->alignment('center')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('storage_bytes')
                    ->label('Storage')
                    ->state(fn (CrawlRun $r) => $r->storage_bytes > 0
                        ? number_format($r->storage_bytes / 1024 / 1024, 1) . ' MB'
                        : '—')
                    ->alignment('center')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CrawlStatus::cases())
                        ->mapWithKeys(fn (CrawlStatus $s) => [$s->value => $s->label()])
                        ->all())
                    ->native(false),

                SelectFilter::make('triggered_by')
                    ->label('Triggered')
                    ->options(collect(TriggerSource::cases())
                        ->mapWithKeys(fn (TriggerSource $t) => [$t->value => $t->label()])
                        ->all())
                    ->native(false),

                SelectFilter::make('site')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                // View → opens the run's viewer. Prefers a 200-status snapshot;
                // falls back to any snapshot at all so every run has a working
                // View action. The viewer shows a "capture failed" state if
                // the snapshot has no HTML, so the link is never a dead end.
                Tables\Actions\Action::make('viewFirstSnapshot')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->color('primary')
                    ->url(function (CrawlRun $r): ?string {
                        $snap = $r->snapshots()
                            ->orderByRaw('status_code = 200 DESC') // prefer 200 rows first
                            ->orderBy('id')
                            ->first();
                        return $snap ? url('/view/' . $snap->id) : null;
                    })
                    ->openUrlInNewTab()
                    ->visible(fn (CrawlRun $r) => $r->snapshots()->exists()),

                // Move to Trash. Soft-deletes the row — recoverable for the
                // trash retention window (default 7 days). Modal previews
                // impact: how many unique vs shared assets, MB to be freed.
                Tables\Actions\Action::make('trash')
                    ->label('Delete')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (CrawlRun $r) => "Delete this crawl run?")
                    ->modalDescription(fn (CrawlRun $r) => static::buildDeleteImpactDescription($r))
                    ->modalSubmitActionLabel('Move to Trash')
                    ->action(function (CrawlRun $r): void {
                        $r->delete(); // soft-delete via SoftDeletes
                        \Filament\Notifications\Notification::make()
                            ->title("Crawl moved to Trash")
                            ->body('Recoverable for 7 days. Disk space frees on the next trash purge.')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (CrawlRun $r) => $r->status->value !== 'running'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('trashSelected')
                        ->label('Delete selected')
                        ->icon('heroicon-m-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Delete selected crawl runs?')
                        ->modalDescription(fn ($records) => sprintf(
                            'Will move %d crawl run(s) to Trash. Recoverable for 7 days. Estimated to free ~%s of disk space when the trash purges.',
                            $records->count(),
                            static::humanBytes((int) $records->sum('storage_bytes')),
                        ))
                        ->modalSubmitActionLabel('Move to Trash')
                        ->action(function ($records): void {
                            CrawlRun::whereIn('id', $records->pluck('id'))->delete();
                            \Filament\Notifications\Notification::make()
                                ->title("{$records->count()} crawls moved to Trash")
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('No crawl runs yet')
            ->emptyStateDescription('Crawls dispatched by the scheduler or manual triggers will appear here.')
            ->emptyStateIcon('heroicon-o-clock')
            // Adaptive poll: 2s while a crawl is Running so the bar
            // animates, 15s when idle. One cheap EXISTS per tick.
            ->poll(fn (): string => CrawlRun::where('status', CrawlStatus::Running)->exists() ? '2s' : '15s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCrawlRuns::route('/'),
        ];
    }

    /**
     * Build the per-row delete confirmation body. Computes how many of
     * this run's assets are unique to it (will free disk) vs shared
     * with other crawls (will keep their pool entries because ref_count
     * stays > 0).
     */
    protected static function buildDeleteImpactDescription(CrawlRun $r): string
    {
        $snapshotIds = \App\Models\Snapshot::where('crawl_run_id', $r->id)->pluck('id');

        $usedFileIds = \App\Models\Asset::whereIn('snapshot_id', $snapshotIds)
            ->whereNotNull('asset_file_id')
            ->pluck('asset_file_id')
            ->unique();

        // A pool file is "unique to this crawl" when its ref_count equals
        // the number of THIS run's assets pointing at it.
        $uniqueCount = 0;
        $sharedCount = 0;
        $bytesToFree = 0;
        foreach ($usedFileIds as $fileId) {
            $assetFile = \App\Models\AssetFile::find($fileId);
            if (! $assetFile) continue;

            $thisRunRefs = \App\Models\Asset::whereIn('snapshot_id', $snapshotIds)
                ->where('asset_file_id', $fileId)
                ->count();

            if ($assetFile->ref_count - $thisRunRefs <= 0) {
                $uniqueCount++;
                $bytesToFree += $assetFile->size_bytes;
            } else {
                $sharedCount++;
            }
        }

        $age = $r->created_at?->diffForHumans() ?? '—';
        return sprintf(
            "Site: %s\nCaptured: %s (%s)\nPages: %d\nAssets: %d unique to this crawl, %d shared with others\nDisk freed when trash purges: ~%s\n\nRecoverable from Trash for 7 days.",
            $r->site->name ?? '—',
            $r->started_at?->format('M j, Y g:i A') ?? '—',
            $age,
            $r->pages_crawled,
            $uniqueCount,
            $sharedCount,
            static::humanBytes($bytesToFree),
        );
    }

    protected static function humanBytes(int $b): string
    {
        if ($b < 1024)         return $b . ' B';
        if ($b < 1024 ** 2)    return round($b / 1024, 1) . ' KB';
        if ($b < 1024 ** 3)    return round($b / 1024 ** 2, 1) . ' MB';
        return round($b / 1024 ** 3, 2) . ' GB';
    }

    // Explicitly deny the create/edit routes — crawl runs are machine-generated.
    public static function canCreate(): bool { return false; }
}
