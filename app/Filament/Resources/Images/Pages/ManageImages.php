<?php

namespace App\Filament\Resources\Images\Pages;

use App\Filament\Resources\Images\ImageResource;
use App\Models\Image;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class ManageImages extends ManageRecords
{
    protected static string $resource = ImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh image details')
                ->color('gray')
                ->icon(Heroicon::OutlinedArrowPath)
                ->tooltip('Re-reads every image file, for files replaced outside the admin, such as by FTP.')
                ->action(function (): void {
                    $missing = [];
                    $updated = 0;

                    foreach (Image::query()->cursor() as $image) {
                        if (! $image->fileExists()) {
                            $missing[] = $image->filename;

                            continue;
                        }

                        $updated += (int) $image->refreshFromFile();
                    }

                    Notification::make()
                        ->title($updated === 1 ? '1 image updated' : $updated.' images updated')
                        ->body($missing === [] ? null : 'These files are missing: '.implode(', ', $missing).'.')
                        ->status($missing === [] ? 'success' : 'warning')
                        ->persistent($missing !== [])
                        ->send();
                }),
            Action::make('upload')
                ->label('Upload images')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalSubmitActionLabel('Upload')
                ->schema([
                    FileUpload::make('files')
                        ->hiddenLabel()
                        ->multiple()
                        ->image()
                        ->acceptedFileTypes(array_keys(Image::TYPES))
                        ->storeFiles(false)
                        ->required()
                        ->helperText('Drop one or more images here. A file whose name is already taken is saved with a number added, so nothing is replaced.'),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $images = collect($data['files'])
                            ->map(fn (TemporaryUploadedFile $file): Image => Image::storeUpload($file));
                    } catch (RuntimeException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Not every image was uploaded')
                            ->body($exception->getMessage())
                            ->persistent()
                            ->send();

                        $action->halt();
                    }

                    Notification::make()
                        ->success()
                        ->title($images->count() === 1 ? 'Image uploaded' : $images->count().' images uploaded')
                        ->body('Saved as '.$images->pluck('filename')->implode(', ').'.')
                        ->send();

                    ImageResource::noticeLargeImages($images);
                }),
        ];
    }
}
