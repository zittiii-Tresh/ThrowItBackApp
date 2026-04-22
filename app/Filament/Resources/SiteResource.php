<?php

namespace App\Filament\Resources;

use App\Enums\FrequencyType;
use App\Enums\NotifyChannel;
use App\Filament\Resources\SiteResource\Pages;
use App\Models\Site;
use App\Support\DetachedCrawl;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Filament resource powering:
 *   - Admin Screen 2 — All sites (table view at /admin/sites)
 *   - Admin Screen 3 — Add site (modal form, shown on the Create page)
 *
 * The Schedules view (Screen 5) lives in a separate custom Filament page —
 * it needs card/grid rendering that Filament's table component doesn't do.
 */
class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon  = 'heroicon-o-globe-alt';
    protected static ?string $navigationLabel = 'All sites';
    protected static ?int    $navigationSort  = 10;

    protected static ?string $modelLabel       = 'site';
    protected static ?string $pluralModelLabel = 'sites';

    // ---------------------------------------------------------------------
    // FORM — drives the "Add site" modal (Screen 3) and Edit page.
    // ---------------------------------------------------------------------
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Site details')
                ->description('What to crawl and how deep.')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Site name')
                        ->placeholder('e.g. Acme main site')
                        ->required()
                        ->maxLength(120),

                    Forms\Components\TextInput::make('base_url')
                        ->label('Base URL')
                        ->placeholder('acme.com or https://www.acme.com')
                        ->helperText('You can paste any form — we prepend https:// automatically if missing.')
                        ->required()
                        ->maxLength(255)
                        // Runs when the field loses focus (including right before
                        // submit), so the normalized value is what gets validated.
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if (blank($state)) {
                                return;
                            }
                            if (! preg_match('#^https?://#i', $state)) {
                                $set('base_url', 'https://' . ltrim($state, '/'));
                            }
                        })
                        // Accepts acme.com, www.acme.com, or https://acme.com —
                        // after the blur mutation above they all normalize to
                        // a scheme-prefixed URL, which this regex then validates.
                        ->rule('regex:/^https?:\/\/[^\s]+\.[^\s]+$/i')
                        ->validationMessages([
                            'regex' => 'Please enter a valid URL, e.g. acme.com or https://www.acme.com',
                        ])
                        ->unique(ignoreRecord: true),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('crawl_depth')
                            ->label('Crawl depth')
                            ->helperText('How many link-levels deep to follow.')
                            ->numeric()->minValue(1)->maxValue(10)
                            ->default(2)->required(),

                        Forms\Components\TextInput::make('max_pages')
                            ->label('Max pages per crawl')
                            ->numeric()->minValue(1)->maxValue(10_000)
                            ->default(500)->required(),
                    ]),
                ])
                ->columns(1),

            Section::make('Crawl frequency')
                ->schema([
                    Forms\Components\ToggleButtons::make('frequency_type')
                        ->options(FrequencyType::options())
                        ->default(FrequencyType::Daily->value)
                        ->required()
                        ->inline()
                        ->live(),

                    // Every N days — number input, only visible when chosen.
                    Forms\Components\TextInput::make('frequency_config.days')
                        ->label('Every how many days?')
                        ->numeric()->minValue(1)->maxValue(90)
                        ->default(2)
                        ->required()
                        ->visible(fn (Get $get) => $get('frequency_type') === FrequencyType::EveryNDays->value),

                    // Specific days of week — checkbox list.
                    Forms\Components\CheckboxList::make('frequency_config.days')
                        ->label('Which days of the week?')
                        ->options([
                            'mon' => 'Monday',
                            'tue' => 'Tuesday',
                            'wed' => 'Wednesday',
                            'thu' => 'Thursday',
                            'fri' => 'Friday',
                            'sat' => 'Saturday',
                            'sun' => 'Sunday',
                        ])
                        ->columns(4)
                        ->visible(fn (Get $get) => $get('frequency_type') === FrequencyType::SpecificDays->value),

                    // Time-of-day picker — stored as "HH:MM" in frequency_config.time.
                    // Used by App\Support\Schedule::nextSpecificDays() to set the
                    // hour/minute on next_run_at. Defaults to midnight.
                    Forms\Components\TimePicker::make('frequency_config.time')
                        ->label('Time of day')
                        ->helperText('Local server time — when the crawl should start on matching days.')
                        ->seconds(false)
                        ->default('00:00')
                        ->visible(fn (Get $get) => $get('frequency_type') === FrequencyType::SpecificDays->value),
                ])
                ->columns(1),

            Section::make('Notifications')
                ->description('Where to send alerts for crawl failures.')
                ->schema([
                    Forms\Components\CheckboxList::make('notify_channels')
                        ->label('Notify on failure')
                        ->options(NotifyChannel::options())
                        ->columns(2)
                        ->default(['email']),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->helperText('Pause to suspend scheduled crawls without losing history.')
                        ->default(true)
                        ->onColor('success'),
                ])
                ->columns(1),
        ]);
    }

    // ---------------------------------------------------------------------
    // TABLE — drives "All sites" (Screen 2).
    // Columns match the PDF mockup: Name / Base URL / Schedule / Last crawl / Active.
    // ---------------------------------------------------------------------
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable()
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('base_url')
                    ->color('gray')
                    ->searchable()
                    ->limit(50)
                    ->alignment('center'),

                // Schedule column uses the model helper — "Every 2 days", "MWF", etc.
                Tables\Columns\TextColumn::make('schedule')
                    ->label('Schedule')
                    ->state(fn (Site $r): string => $r->describeFrequency())
                    ->badge()
                    ->color('primary')
                    ->alignment('center'),

                // Total bytes archived across every crawl run for this site.
                // Sums at query time — table is small, no need to cache.
                Tables\Columns\TextColumn::make('storage_total')
                    ->label('Storage')
                    ->state(function (Site $r): string {
                        $bytes = (int) $r->crawlRuns()->sum('storage_bytes');
                        if ($bytes === 0) return '—';
                        return match (true) {
                            $bytes < 1024           => $bytes . ' B',
                            $bytes < 1024 ** 2      => number_format($bytes / 1024, 1) . ' KB',
                            $bytes < 1024 ** 3      => number_format($bytes / 1024 ** 2, 1) . ' MB',
                            default                 => number_format($bytes / 1024 ** 3, 2) . ' GB',
                        };
                    })
                    ->alignment('center'),

                // Live status column — shows an animated progress bar while a
                // crawl is in flight, or the last run's status + time-ago when
                // idle. Rendered by resources/views/filament/columns/crawl-status.blade.php
                // and kept fresh by the table's ->poll() below.
                Tables\Columns\ViewColumn::make('crawlStatus')
                    ->label('Status')
                    ->view('filament.columns.crawl-status')
                    ->alignment('center'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active')
                    ->onColor('success')
                    ->alignment('center'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active status')
                    ->trueLabel('Active only')
                    ->falseLabel('Paused only')
                    ->native(false),
            ])
            ->actions([
                // "Crawl now" — creates a CrawlRun row in Running state
                // SYNCHRONOUSLY so the table's polling immediately sees it,
                // then spawns a detached background process to do the heavy
                // lifting. Without the synchronous row creation, the first
                // 2s poll tick hits before php-cli has booted and the user
                // sees "nothing happening."
                Tables\Actions\Action::make('crawlNow')
                    ->label('Crawl now')
                    ->icon('heroicon-m-bolt')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Site $record) => "Crawl {$record->name} now?")
                    ->modalDescription('Runs immediately in the background. Progress appears in Crawl History and the dashboard.')
                    ->modalSubmitActionLabel('Start crawl')
                    ->action(function (Site $record): void {
                        // Clear scheduler pickup so the minute-tick won't also
                        // dispatch this site while the manual run is in flight.
                        $record->update(['next_run_at' => null]);

                        // Pre-create the run row NOW so the table's next poll
                        // already sees a "Running" row for this site.
                        $run = \App\Models\CrawlRun::create([
                            'site_id'      => $record->id,
                            'status'       => \App\Enums\CrawlStatus::Running,
                            'triggered_by' => \App\Enums\TriggerSource::Manual,
                            'started_at'   => now(),
                        ]);

                        // Fully detached subprocess that keeps running after
                        // the Filament request returns. See DetachedCrawl for
                        // the Windows console-flash quirks this avoids.
                        DetachedCrawl::spawn($record->id, $run->id);
                    })
                    ->successNotificationTitle(fn (Site $record) => "Crawling {$record->name} — watch progress in the Status column"),

                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No sites yet')
            ->emptyStateDescription('Register the first site to start archiving.')
            ->emptyStateIcon('heroicon-o-globe-alt')
            ->defaultSort('name')
            // Auto-refresh every 2s so the Status column's progress bar
            // ticks forward live while a crawl is running. Filament runs
            // a cheap single query per tick — no background websockets.
            ->poll('2s');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit'   => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
