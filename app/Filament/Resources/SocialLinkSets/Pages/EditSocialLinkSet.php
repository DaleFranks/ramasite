<?php

namespace App\Filament\Resources\SocialLinkSets\Pages;

use App\Filament\InUseCheck;
use App\Filament\Resources\SocialLinkSets\SocialLinkSetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSocialLinkSet extends EditRecord
{
    protected static string $resource = SocialLinkSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(InUseCheck::beforeDeleting()),
        ];
    }
}
