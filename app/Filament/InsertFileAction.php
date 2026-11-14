<?php

namespace App\Filament;

use App\Filament\Resources\LibraryFiles\LibraryFileResource;
use App\Models\LibraryFile;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The content editors' way of linking to a file: choose one from the file
 * library or upload one to it, give the link its text, and choose whether its
 * type and size are shown after it. A [file:...] shortcode goes in at the cursor.
 *
 * It sits above the editor beside Insert image, and uses the same script to
 * put its shortcode in place.
 */
class InsertFileAction
{
    private const SOURCE_LIBRARY = 'library';

    private const SOURCE_UPLOAD = 'upload';

    /**
     * How many files the list shows at a time; searching reaches the rest.
     */
    private const LISTED_FILES = 50;

    public static function make(): Action
    {
        return Action::make('insertFile')
            ->label('Insert file')
            ->icon(Heroicon::OutlinedPaperClip)
            ->modalHeading('Insert a link to a file')
            ->modalSubmitActionLabel('Insert')
            ->schema([
                ToggleButtons::make('source')
                    ->hiddenLabel()
                    ->options([
                        self::SOURCE_LIBRARY => 'File library',
                        self::SOURCE_UPLOAD => 'Upload',
                    ])
                    ->default(self::SOURCE_LIBRARY)
                    ->inline()
                    ->grouped()
                    ->required()
                    ->live(),
                Select::make('file')
                    ->label('File')
                    ->placeholder('Search by name')
                    ->searchable()
                    ->options(fn (): array => self::optionsFor(LibraryFile::query()->latest()))
                    ->getSearchResultsUsing(fn (string $search): array => self::optionsFor(
                        LibraryFile::query()->whereRaw("filename like ? escape '\\'", [InsertImageAction::likePattern($search)])->orderBy('filename'),
                    ))
                    ->getOptionLabelUsing(fn (string $value): ?string => ($file = LibraryFile::query()->find($value)) ? self::optionLabel($file) : null)
                    ->required()
                    ->visible(fn (Get $get): bool => $get('source') === self::SOURCE_LIBRARY),
                LibraryFileResource::uploadField('upload')
                    ->label('File')
                    ->helperText('It\'s added to the file library. '.Str::ucfirst(LibraryFile::typeList()).' can be uploaded. A file whose name is already taken is saved with a number added, so nothing is replaced.')
                    ->visible(fn (Get $get): bool => $get('source') === self::SOURCE_UPLOAD),
                TextInput::make('text')
                    ->label('Link text')
                    ->placeholder('The file\'s name')
                    ->maxLength(255)
                    ->helperText('What visitors click, such as "Our menu". Left empty, the link shows the file\'s name.'),
                Toggle::make('details')
                    ->label('Show the type and size after the link')
                    ->helperText('Such as "Our menu (PDF, 240 KB)". The size stays up to date if the file is replaced.')
                    ->default(true),
            ])
            ->action(function (Action $action, Component $livewire, array $data): void {
                $file = $data['source'] === self::SOURCE_UPLOAD
                    ? self::uploadToLibrary($data['upload'])
                    : LibraryFile::query()->findOrFail((int) $data['file']);

                $livewire->dispatch(
                    'insert-into-editor',
                    editorId: $action->getSchemaComponent()->getId(),
                    text: self::shortcode($file, $data['text'] ?? null, (bool) ($data['details'] ?? false)),
                );
            });
    }

    /**
     * The shortcode for a link to the file. The link text lives in the
     * shortcode, where "|" and "]" would be read as ending it.
     */
    public static function shortcode(LibraryFile $file, ?string $text, bool $withDetails): string
    {
        $text = trim(str_replace(['|', ']'], ' ', (string) $text));

        // A text of "details" would be read as asking for the details
        if ($text === 'details') {
            $text = 'Details';
        }

        return '[file:'.$file->filename
            .($text === '' ? '' : '|'.$text)
            .($withDetails ? '|details' : '')
            .']';
    }

    private static function uploadToLibrary(TemporaryUploadedFile $upload): LibraryFile
    {
        $file = LibraryFile::storeUpload($upload);

        $wasNumbered = pathinfo($file->filename, PATHINFO_FILENAME) !== Str::slug(pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME));

        Notification::make()
            ->success()
            ->title('Added to the file library as '.$file->filename)
            ->body($wasNumbered ? 'That name was already taken, so this is a separate file. If it\'s the same file, you can choose the existing one from the library instead.' : null)
            ->persistent($wasNumbered)
            ->send();

        return $file;
    }

    /**
     * @param  Builder<LibraryFile>  $files
     * @return array<int, string>
     */
    private static function optionsFor(Builder $files): array
    {
        return $files
            ->limit(self::LISTED_FILES)
            ->get()
            ->mapWithKeys(fn (LibraryFile $file): array => [$file->id => self::optionLabel($file)])
            ->all();
    }

    /**
     * The file's name with its type and size, e.g. "menu.pdf (PDF, 240 KB)".
     */
    private static function optionLabel(LibraryFile $file): string
    {
        return $file->filename.' ('.$file->details().')';
    }
}
