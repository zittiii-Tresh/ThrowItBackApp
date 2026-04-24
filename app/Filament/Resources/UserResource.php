<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Admin user management — a sidebar tab where existing admins can invite
 * new admins. New accounts start unverified; they can't access /admin until
 * they click the verification link Laravel emails them on create.
 *
 * Guard rails baked in:
 *   - You can't delete yourself (prevents locking out the only admin).
 *   - Password field is required only on create; leaving it blank on edit
 *     preserves the existing hash.
 *   - "Resend verification" row action for unverified users whose original
 *     email got lost or expired.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Admins';
    protected static ?int    $navigationSort  = 50;

    protected static ?string $modelLabel       = 'admin';
    protected static ?string $pluralModelLabel = 'admins';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Account')
                ->description('Verification email goes out after saving. The new admin clicks the link and sets a fresh session from there.')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(120),

                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->confirmed()
                        // Use the project-wide strong defaults defined in
                        // AppServiceProvider::configurePasswordDefaults()
                        // — min 12, mixed case, numbers, symbols, and not
                        // present in any known breach dataset.
                        ->rule(Password::default())
                        ->helperText('Min 12 chars, mixed case, numbers, symbols. Checked against known breaches. Leave blank on edit to keep current password.')
                        // Required only when creating; optional on edit.
                        ->required(fn (string $operation) => $operation === 'create')
                        // Hash if provided; skip the column entirely when blank.
                        ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                        ->dehydrated(fn (?string $state) => filled($state)),

                    Forms\Components\TextInput::make('password_confirmation')
                        ->password()
                        ->revealable()
                        ->requiredWith('password')
                        ->dehydrated(false),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable()
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('email')
                    ->copyable()
                    ->searchable()
                    ->alignment('center'),

                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn (User $u) => $u->hasVerifiedEmail())
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->alignment('center'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->since()
                    ->sortable()
                    ->alignment('center'),
            ])
            ->actions([
                // Resend verification — only visible on unverified rows.
                Tables\Actions\Action::make('resendVerification')
                    ->label('Resend verification')
                    ->icon('heroicon-m-envelope')
                    ->color('warning')
                    ->visible(fn (User $u) => ! $u->hasVerifiedEmail())
                    ->requiresConfirmation()
                    ->action(function (User $u): void {
                        $u->sendEmailVerificationNotification();
                        Notification::make()
                            ->title("Verification email re-sent to {$u->email}")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),

                // Delete is always surfaced inline (not hidden in a dropdown)
                // so it's one click away. Hidden only on your own row to
                // prevent self-lockout.
                Tables\Actions\DeleteAction::make()
                    ->label('Delete')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $u) => "Delete admin {$u->name}?")
                    ->modalDescription('They will lose access to the admin panel immediately. This cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete admin')
                    ->successNotificationTitle(fn (User $u) => "{$u->name} deleted")
                    ->visible(fn (User $u) => $u->id !== auth()->id()),
            ])
            ->bulkActions([
                // Bulk delete — skips your own row automatically.
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (\Illuminate\Database\Eloquent\Collection $records) {
                            // Drop yourself from the selected set before the
                            // delete runs — prevents self-lockout even if your
                            // row slipped into the selection.
                            $records->reject(fn (User $u) => $u->id === auth()->id());
                        }),
                ]),
            ])
            ->emptyStateHeading('No admins yet')
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
