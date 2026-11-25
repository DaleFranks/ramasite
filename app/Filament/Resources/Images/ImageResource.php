<?php

namespace App\Filament\Resources\Images;

use App\Filament\Resources\Images\Pages\ManageImages;
use App\Models\Image;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use RuntimeException;

class ImageResource extends Resource
{
    protected static ?string $model = Image::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $recordTitleAttribute = 'filename';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Text::make(fn (Image $record): string => 'Place it in a page with '.$record->shortcode().'. Add a width, an alignment or alt text after the name, e.g. [image:'.$record->filename.'|400|right|Our team].'),
                TextInput::make('alt_text')
                    ->label('Alt text')
                    ->maxLength(255)
                    ->helperText('Read aloud by screen readers and shown if the image can\'t load. Used wherever the shortcode doesn\'t give its own alt text.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->contentGrid([
                'md' => 2,
                'lg' => 3,
                'xl' => 4,
            ])
            ->columns([
                Stack::make([
                    ImageColumn::make('preview')
                        ->state(fn (Image $record): string => $record->versionedPath())
                        ->disk(Image::DISK)
                        ->checkFileExistence(false)
                        ->alignCenter()
                        ->imageHeight(160)
                        ->extraImgAttributes(['style' => 'max-width: 100%; object-fit: contain;', 'loading' => 'lazy']),
                    TextColumn::make('filename')
                        ->weight('medium')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('shortcode')
                        ->state(fn (Image $record): string => $record->shortcode())
                        ->fontFamily('mono')
                        ->size('sm')
                        ->copyable()
                        ->copyMessage('Shortcode copied'),
                    TextColumn::make('details')
                        ->state(fn (Image $record): string => "{$record->width} × {$record->height} · ".Number::fileSize($record->size))
                        ->color('gray')
                        ->size('sm'),
                    TextColumn::make('large_file')
                        ->state(fn (Image $record): ?string => $record->isLarge() ? 'Large file' : null)
                        ->color('warning')
                        ->weight('medium')
                        ->size('sm'),
                    TextColumn::make('alt_text')
                        ->color('gray')
                        ->size('sm')
                        ->placeholder('No alt text')
                        ->limit(60)
                        ->searchable(),
                ])->space(2),
            ])
            ->recordActions([
                self::replaceAction(),
                EditAction::make()
                    ->modalHeading(fn (Image $record): string => 'Edit '.$record->filename),
                DeleteAction::make()
                    ->before(function (DeleteAction $action, Image $record): void {
                        $usages = $record->usages();

                        if ($usages->isEmpty()) {
                            return;
                        }

                        Notification::make()
                            ->danger()
                            ->title($record->filename.' is in use and can\'t be deleted')
                            ->body('Remove it from these first: '.$usages->implode(', ').'.')
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }),
            ]);
    }

    /**
     * Swaps a new file in under an image's name, for every place it's used.
     */
    private static function replaceAction(): Action
    {
        return Action::make('replace')
            ->icon(Heroicon::OutlinedArrowPath)
            ->modalHeading(fn (Image $record): string => 'Replace '.$record->filename)
            ->modalSubmitActionLabel('Replace')
            ->schema(fn (Image $record): array => [
                Text::make(new HtmlString(
                    '<img src="'.e($record->url()).'" alt="" style="max-width: 100%; max-height: 160px; object-fit: contain;">',
                )),
                Text::make(self::whereUsedText($record).' The new file keeps this name, so they\'ll all show it. The current file is overwritten and can\'t be brought back, except from a backup.'),
                FileUpload::make('file')
                    ->hiddenLabel()
                    ->image()
                    ->acceptedFileTypes(array_keys(Image::TYPES))
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (Action $action, Image $record, array $data): void {
                try {
                    $record->replaceWith($data['file']);
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('The image wasn\'t replaced')
                        ->body($exception->getMessage())
                        ->persistent()
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->success()
                    ->title($record->filename.' replaced')
                    ->body("Now {$record->width} × {$record->height} · ".Number::fileSize($record->size))
                    ->send();

                self::noticeLargeImages(collect([$record]));
            });
    }

    /**
     * Mentions any images that may load slowly, so they can be resized and replaced.
     *
     * @param  Collection<int, Image>  $images
     */
    public static function noticeLargeImages(Collection $images): void
    {
        $largeImages = $images->filter->isLarge();

        if ($largeImages->isEmpty()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title($largeImages->count() === 1 ? 'This image may load slowly' : 'These images may load slowly')
            ->body($largeImages->map(fn (Image $image): string => $image->filename.' is '.Number::fileSize($image->size))->implode(', ').'. Images are kept as uploaded, so consider resizing or compressing and using Replace.')
            ->persistent()
            ->send();
    }

    /**
     * What an upload of several files that stopped partway says: why, and which
     * files went in before it stopped, as the popup still lists them all.
     *
     * @param  list<string>  $addedFilenames
     */
    public static function uploadStoppedMessage(string $problem, array $addedFilenames): string
    {
        if ($addedFilenames === []) {
            return $problem;
        }

        return $problem.' These were added before it stopped: '.implode(', ', $addedFilenames).'. Take them out of the list before uploading again, or they\'ll be added a second time.';
    }

    private static function whereUsedText(Image $image): string
    {
        $usages = $image->usages();

        return $usages->isEmpty()
            ? 'This image isn\'t used anywhere yet.'
            : 'This image is used in: '.$usages->implode(', ').'.';
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageImages::route('/'),
        ];
    }
}
