<?php

namespace App\Filament\Resources\Entries\Pages;

use App\Filament\Resources\Entries\EntryResource;
use App\Filament\ViewOnSiteAction;
use App\Models\Entry;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEntry extends EditRecord
{
    protected static string $resource = EntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewOnSiteAction::make()
                ->tooltip('Opens the page as last saved, in a new tab'),
            DeleteAction::make()
                ->hidden(fn (Entry $record): bool => $record->isHomePage())
                ->failureNotificationTitle('This blog still has posts. Move or delete them first.'),
        ];
    }

    /**
     * Show the publish date filled in when an entry is first published.
     */
    protected function afterSave(): void
    {
        $this->refreshFormData(['published_at']);
    }
}
