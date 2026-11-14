<?php

namespace App\Filament\Resources\LibraryFiles\Pages;

use App\Filament\Resources\LibraryFiles\LibraryFileResource;
use App\Models\LibraryFile;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class ManageLibraryFiles extends ManageRecords
{
    protected static string $resource = LibraryFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload files')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalSubmitActionLabel('Upload')
                ->schema([
                    LibraryFileResource::uploadField('files')
                        ->hiddenLabel()
                        ->multiple()
                        ->helperText('Drop one or more files here: '.LibraryFile::typeList().'. A file whose name is already taken is saved with a number added, so nothing is replaced.'),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $files = collect($data['files'])
                            ->map(fn (TemporaryUploadedFile $file): LibraryFile => LibraryFile::storeUpload($file));
                    } catch (RuntimeException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Not every file was uploaded')
                            ->body($exception->getMessage())
                            ->persistent()
                            ->send();

                        $action->halt();
                    }

                    Notification::make()
                        ->success()
                        ->title($files->count() === 1 ? 'File uploaded' : $files->count().' files uploaded')
                        ->body('Saved as '.$files->pluck('filename')->implode(', ').'.')
                        ->send();
                }),
        ];
    }
}
