<?php

namespace App\Filament\Resources\Forms\Pages;

use App\Filament\InUseCheck;
use App\Filament\Resources\Forms\FormResource;
use App\Models\Form;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditForm extends EditRecord
{
    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadSubmissions')
                ->label('Download submissions (CSV)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->disabled(fn (Form $record): bool => ! $record->submissions()->exists())
                ->action(fn (Form $record): StreamedResponse => $record->downloadSubmissionsCsv()),
            DeleteAction::make()
                ->before(InUseCheck::beforeDeleting())
                ->modalDescription(fn (Form $record): string => match ($count = $record->submissions()->count()) {
                    0 => 'Are you sure you would like to do this?',
                    1 => 'Its 1 submission will be deleted too.',
                    default => "Its {$count} submissions will be deleted too.",
                }),
        ];
    }
}
