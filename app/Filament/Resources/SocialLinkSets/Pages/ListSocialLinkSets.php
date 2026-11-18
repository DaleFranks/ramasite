<?php

namespace App\Filament\Resources\SocialLinkSets\Pages;

use App\Filament\Resources\SocialLinkSets\SocialLinkSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSocialLinkSets extends ListRecords
{
    protected static string $resource = SocialLinkSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
