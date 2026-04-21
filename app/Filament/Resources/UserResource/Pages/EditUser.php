<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Same guard rail as the row action — can't delete yourself.
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->id !== auth()->id()),
        ];
    }
}
