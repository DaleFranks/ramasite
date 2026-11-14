<?php

namespace App\Filament\Resources\Modules\Pages;

use App\Filament\InUseCheck;
use App\Filament\Resources\Modules\ModuleResource;
use App\Models\Module;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditModule extends EditRecord
{
    protected static string $resource = ModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (Module $record): bool => $record->isFooter())
                ->before(InUseCheck::beforeDeleting()),
        ];
    }
}
