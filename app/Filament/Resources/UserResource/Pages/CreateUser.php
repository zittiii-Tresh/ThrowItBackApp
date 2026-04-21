<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Events\Registered;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Fire the Registered event after creation so Laravel's built-in
     * SendEmailVerificationNotification listener dispatches the verification
     * email. Without this the new admin would never get a link and
     * canAccessPanel() would permanently block them.
     */
    protected function afterCreate(): void
    {
        event(new Registered($this->record));

        Notification::make()
            ->title("Verification email sent to {$this->record->email}")
            ->body('The new admin must click the link in their email before they can log in.')
            ->success()
            ->send();
    }
}
