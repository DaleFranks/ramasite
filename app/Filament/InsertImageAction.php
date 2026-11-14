<?php

namespace App\Filament;

use App\Filament\Resources\Images\ImageResource;
use App\Markdown;
use App\Models\Image;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The content editors' way of adding an image: choose one from the library, upload
 * one to it, or give the address of one kept elsewhere. Either way a shortcode goes
 * in at the cursor, ready to have a width and alignment added.
 *
 * The editor's toolbar takes no custom buttons, so this sits above the editor.
 */
class InsertImageAction
{
    private const SOURCE_LIBRARY = 'library';

    private const SOURCE_UPLOAD = 'upload';

    private const SOURCE_ADDRESS = 'address';

    /**
     * How many images the list shows at a time; searching reaches the rest.
     */
    public const LISTED_IMAGES = 50;

    /**
     * A full image address that a shortcode can hold: "|" and "]" would end it early.
     */
    private const ADDRESS_PATTERN = '/^https?:\/\/[^\s|\]]+$/i';

    public static function make(): Action
    {
        return Action::make('insertImage')
            ->label('Insert image')
            ->icon(Heroicon::OutlinedPhoto)
            ->modalHeading('Insert image')
            ->modalSubmitActionLabel('Insert')
            ->schema([
                ToggleButtons::make('source')
                    ->hiddenLabel()
                    ->options([
                        self::SOURCE_LIBRARY => 'Image library',
                        self::SOURCE_UPLOAD => 'Upload',
                        self::SOURCE_ADDRESS => 'Image address',
                    ])
                    ->default(self::SOURCE_LIBRARY)
                    ->inline()
                    ->grouped()
                    ->required()
                    ->live(),
                Select::make('image')
                    ->label('Image')
                    ->placeholder('Search by name or alt text')
                    ->searchable()
                    ->allowHtml()
                    ->options(fn (): array => self::optionsFor(Image::query()->latest()))
                    ->getSearchResultsUsing(fn (string $search): array => self::optionsFor(self::matching($search)))
                    ->getOptionLabelUsing(fn (string $value): ?string => ($image = Image::query()->find($value)) ? self::optionLabel($image) : null)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('alt_text', Image::query()->find($state)?->alt_text))
                    ->visible(fn (Get $get): bool => $get('source') === self::SOURCE_LIBRARY),
                FileUpload::make('file')
                    ->label('Image file')
                    ->image()
                    ->acceptedFileTypes(array_keys(Image::TYPES))
                    ->storeFiles(false)
                    ->required()
                    ->helperText('It\'s added to the image library. A file whose name is already taken is saved with a number added, so nothing is replaced.')
                    ->visible(fn (Get $get): bool => $get('source') === self::SOURCE_UPLOAD),
                TextInput::make('address')
                    ->label('Image address')
                    ->placeholder('https://')
                    ->maxLength(2000)
                    ->regex(self::ADDRESS_PATTERN)
                    ->validationMessages(['regex' => 'Enter a full address starting with https:// or http://, with no spaces.'])
                    ->required()
                    ->helperText('This image stays where it is and isn\'t added to your library.')
                    ->visible(fn (Get $get): bool => $get('source') === self::SOURCE_ADDRESS),
                TextInput::make('alt_text')
                    ->label('Alt text')
                    ->maxLength(255)
                    // In the shortcode, alt text that looks like a width or alignment would be read as one
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $altText = trim(str_replace(['|', ']'], ' ', (string) $value));

                        if ($get('source') === self::SOURCE_ADDRESS && (Markdown::isImageSize($altText) || Markdown::isImageAlignment($altText))) {
                            $fail('On its own, "'.$altText.'" would be read as the image\'s size or position. Add a word or two, such as "Left side of the building".');
                        }
                    })
                    ->helperText(fn (Get $get): string => $get('source') === self::SOURCE_ADDRESS
                        ? 'Read aloud by screen readers and shown if the image can\'t load. It goes in the shortcode, so it also shows as a tooltip.'
                        : 'Read aloud by screen readers and shown if the image can\'t load. It\'s saved in the library, for wherever this image is used without its own alt text.'),
            ])
            ->action(function (Action $action, Component $livewire, array $data): void {
                $livewire->dispatch(
                    'insert-into-editor',
                    editorId: $action->getSchemaComponent()->getId(),
                    text: match ($data['source']) {
                        self::SOURCE_UPLOAD => self::uploadToLibrary($data['file'], $data['alt_text'] ?? null)->shortcode(),
                        self::SOURCE_ADDRESS => self::addressShortcode($data['address'], $data['alt_text'] ?? null),
                        default => self::saveAltText((int) $data['image'], $data['alt_text'] ?? null)->shortcode(),
                    },
                );
            });
    }

    private static function saveAltText(int $imageId, ?string $altText): Image
    {
        $image = Image::query()->findOrFail($imageId);
        $image->update(['alt_text' => $altText]);

        return $image;
    }

    private static function uploadToLibrary(TemporaryUploadedFile $file, ?string $altText): Image
    {
        $image = Image::storeUpload($file);
        $image->update(['alt_text' => $altText]);

        $wasNumbered = pathinfo($image->filename, PATHINFO_FILENAME) !== Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        Notification::make()
            ->success()
            ->title('Added to the image library as '.$image->filename)
            ->body($wasNumbered ? 'That name was already taken, so this is a separate image. If it\'s the same picture, you can choose the existing one from the library instead.' : null)
            ->persistent($wasNumbered)
            ->send();

        ImageResource::noticeLargeImages(collect([$image]));

        return $image;
    }

    /**
     * The shortcode for an image kept elsewhere. Its alt text can only live in the
     * shortcode, where "|" and "]" would be read as ending it.
     */
    private static function addressShortcode(string $address, ?string $altText): string
    {
        $altText = trim(str_replace(['|', ']'], ' ', (string) $altText));

        return '[image:'.$address.($altText === '' ? '' : '|'.$altText).']';
    }

    /**
     * @param  Builder<Image>  $images
     * @return array<int, string>
     */
    private static function optionsFor(Builder $images): array
    {
        return $images
            ->limit(self::LISTED_IMAGES)
            ->get()
            ->mapWithKeys(fn (Image $image): array => [$image->id => self::optionLabel($image)])
            ->all();
    }

    /**
     * Images whose name or alt text contains the search, by name.
     *
     * @return Builder<Image>
     */
    public static function matching(string $search): Builder
    {
        $pattern = self::likePattern($search);

        return Image::query()
            ->where(fn (Builder $images): Builder => $images
                ->whereRaw("filename like ? escape '\\'", [$pattern])
                ->orWhereRaw("alt_text like ? escape '\\'", [$pattern]))
            ->orderBy('filename');
    }

    /**
     * A search as a "contains" pattern for LIKE, with "%" and "_" meaning
     * themselves rather than standing for any characters.
     */
    public static function likePattern(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
    }

    /**
     * A thumbnail beside the image's name and alt text.
     */
    private static function optionLabel(Image $image): string
    {
        return '<span style="display: flex; align-items: center; gap: 0.75rem;">'
            .'<img src="'.e($image->url()).'" alt="" loading="lazy" style="width: 48px; height: 48px; flex: none; object-fit: contain;">'
            .'<span>'.e($image->filename).'<br><small>'.e($image->alt_text ?: 'No alt text').'</small></span>'
            .'</span>';
    }
}
