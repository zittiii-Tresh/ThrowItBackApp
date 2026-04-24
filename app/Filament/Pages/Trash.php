<?php

namespace App\Filament\Pages;

use App\Models\CrawlRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Admin Screen — Trash.
 *
 * Lists soft-deleted crawl runs (deleted_at IS NOT NULL). Each row shows
 * how many days remain before the trash purge hard-deletes it. Admin can:
 *   - Restore a run (clears deleted_at)
 *   - Purge a run early (force-delete + free disk space immediately)
 *   - Empty the trash (purge ALL trashed runs, regardless of age)
 *
 * Default: trash runs auto-purge 7 days after deletion via the nightly
 * `archive:trash-purge` command.
 */
class Trash extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon  = 'heroicon-o-trash';
    protected static ?string $navigationLabel = 'Trash';
    protected static ?int    $navigationSort  = 60;

    protected static string $view = 'filament.pages.trash';

    public function getHeading(): string|Htmlable
    {
        return 'Trash';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $count = CrawlRun::onlyTrashed()->count();
        return $count === 0
            ? 'Nothing in trash.'
            : "{$count} crawl run(s) — auto-purge after 7 days. Restore or purge early below.";
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CrawlRun::onlyTrashed()->with('site'))
            ->defaultSort('deleted_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('site.name')
                    ->label('Site')
                    ->weight('semibold')
                    ->alignment('center')
                    ->searchable(),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Captured')
                    ->dateTime('M j, H:i')
                    ->placeholder('—')
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('pages_crawled')
                    ->label('Pages')
                    ->numeric()
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('storage_bytes')
                    ->label('Approx. size')
                    ->state(fn (CrawlRun $r) => $r->storage_bytes > 0
                        ? round($r->storage_bytes / 1024 / 1024, 1) . ' MB'
                        : '—')
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('deleted_at')
                    ->label('Trashed')
                    ->since()
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('purges_in')
                    ->label('Auto-purges')
                    ->state(function (CrawlRun $r): string {
                        $purgeAt = $r->deleted_at?->copy()->addDays(7);
                        if (! $purgeAt) return '—';
                        if ($purgeAt->isPast()) return 'Tonight';
                        return 'in ' . now()->diffInDays($purgeAt) . 'd';
                    })
                    ->alignment('center'),
            ])
            ->actions([
                Tables\Actions\Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Move this crawl back into Crawl History.')
                    ->action(function (CrawlRun $r): void {
                        $r->restore();
                        Notification::make()->title('Crawl restored')->success()->send();
                    }),

                Tables\Actions\Action::make('purgeNow')
                    ->label('Purge now')
                    ->icon('heroicon-m-fire')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Permanently delete this crawl?')
                    ->modalDescription('Frees disk space immediately. This cannot be undone.')
                    ->modalSubmitActionLabel('Yes, purge')
                    ->action(function (CrawlRun $r): void {
                        \Illuminate\Support\Facades\Artisan::call('archive:trash-purge', [
                            '--age' => 0,
                        ]);
                        Notification::make()->title('Purge triggered — disk space freed')->success()->send();
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Trash is empty')
            ->emptyStateDescription('Deleted crawls land here for 7 days before being permanently removed.')
            ->emptyStateIcon('heroicon-o-trash');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('emptyTrash')
                ->label('Empty trash now')
                ->icon('heroicon-m-fire')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Empty the entire trash?')
                ->modalDescription('Permanently deletes ALL trashed crawls and frees their disk space immediately. Cannot be undone.')
                ->modalSubmitActionLabel('Yes, empty trash')
                ->action(function (): void {
                    \Illuminate\Support\Facades\Artisan::call('archive:trash-purge', [
                        '--age' => 0,
                    ]);
                    Notification::make()->title('Trash emptied')->success()->send();
                })
                ->visible(fn () => CrawlRun::onlyTrashed()->exists()),
        ];
    }
}
