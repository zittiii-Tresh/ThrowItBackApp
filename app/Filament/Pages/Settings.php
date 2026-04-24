<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Pages\Page;

/**
 * Admin Screen 7 — Settings.
 *
 * Stock Filament "form page" pattern: mount() hydrates the form from the
 * Setting singleton, save() persists changes back. One row ever exists.
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Settings';
    protected static ?int    $navigationSort  = 40;

    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(Setting::current()->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Storage')
                    ->description('Where archived content is saved and how long it\'s kept.')
                    ->schema([
                        Forms\Components\ToggleButtons::make('storage_driver')
                            ->label('Storage driver')
                            ->options([
                                'local' => 'Local disk',
                                's3'    => 'Amazon S3',
                            ])
                            ->inline()
                            ->default('local')
                            ->helperText('Local is fine for dev and small deployments. Switch to S3 for production.'),

                        Forms\Components\Select::make('default_retention_months')
                            ->label('Default retention')
                            ->helperText('Sites without their own retention setting use this. Older crawls go to Trash; Trash purges 7 days later, freeing disk space.')
                            ->options([
                                1  => 'Keep 1 month',
                                2  => 'Keep 2 months',
                                3  => 'Keep 3 months',
                                6  => 'Keep 6 months',
                                12 => 'Keep 12 months',
                                0  => 'Keep forever (never auto-delete)',
                            ])
                            ->default(3)
                            ->native(false)
                            ->required(),

                        Forms\Components\Select::make('cleanup_hour')
                            ->label('Nightly cleanup time')
                            ->helperText('When the daily retention + trash purge jobs run.')
                            ->options(collect(range(0, 23))
                                ->mapWithKeys(fn ($h) => [$h => sprintf('%02d:00', $h)])
                                ->all())
                            ->default(3)
                            ->native(false),

                        Forms\Components\TextInput::make('storage_limit_gb')
                            ->label('Storage budget (GB)')
                            ->helperText('Warning fires when used storage exceeds this budget.')
                            ->numeric()
                            ->minValue(1)
                            ->default(50)
                            ->suffix('GB'),
                    ])
                    ->columns(1),

                Section::make('Notifications')
                    ->description('Where to send alerts, and which events trigger them.')
                    ->schema([
                        Forms\Components\TextInput::make('email_recipients')
                            ->label('Email recipients')
                            ->helperText('Comma-separated list of email addresses.')
                            ->placeholder('admin@sitesatscale.com, alerts@sitesatscale.com'),

                        Forms\Components\TextInput::make('slack_webhook_url')
                            ->label('Slack webhook URL')
                            ->placeholder('https://hooks.slack.com/services/…')
                            ->url(),

                        Toggle::make('notify_on_crawl_failure')
                            ->label('Notify on crawl failure')
                            ->default(true)
                            ->onColor('success'),

                        Toggle::make('notify_on_storage_warning')
                            ->label('Notify on storage warning')
                            ->default(true)
                            ->onColor('success'),

                        Toggle::make('notify_on_crawl_success')
                            ->label('Notify on every successful crawl')
                            ->helperText('Leaving this off reduces noise — you still see completions on the Dashboard.')
                            ->default(false)
                            ->onColor('success'),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        Setting::current()->update($this->form->getState());

        FilamentNotification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Save settings')
                ->submit('save'),
        ];
    }
}
