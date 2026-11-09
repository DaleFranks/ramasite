<?php

namespace App\Filament\Resources\Sliders\Schemas;

use App\Filament\Pages\ThemeSettings;
use App\Models\Image;
use App\Models\Slider;
use App\SlideCaptionPosition;
use App\SliderAnimation;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SliderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, ?string $state, Get $get, Set $set): void {
                                if ($operation === 'create' && ! $get('slug_is_manual')) {
                                    $set('slug', Str::slug($state ?? '') ?: null);
                                }
                            }),
                        Hidden::make('slug_is_manual')
                            ->default(false)
                            ->dehydrated(false),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set) => $set('slug_is_manual', filled($state)))
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->validationMessages([
                                'regex' => 'Use lowercase letters, numbers and hyphens only.',
                            ])
                            ->unique(ignoreRecord: true)
                            ->helperText(fn (Get $get): string => 'Place the slider in a page by writing [slider:'.($get('slug') ?: 'slug').'] in its content.'),
                        ToggleButtons::make('animation')
                            ->options(SliderAnimation::class)
                            ->default(SliderAnimation::Slide)
                            ->inline()
                            ->required(),
                        TextInput::make('seconds_per_slide')
                            ->label('Seconds between slides')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(600)
                            ->suffix('seconds')
                            ->helperText('Leave empty to change slides only when the visitor clicks.'),
                        Toggle::make('edge_to_edge')
                            ->label('Edge to edge on Landing pages')
                            ->live()
                            ->columnSpanFull()
                            ->helperText(fn (Get $get): ?string => $get('edge_to_edge')
                                ? 'On a page with the Landing layout, this slider runs the full width of the window, stacked between modules like a band. On other layouts it fills the content column as usual. Use images at least '.Slider::EDGE_TO_EDGE_IMAGE_WIDTH.' pixels wide.'
                                : null),
                    ]),
                Section::make('Slides')
                    ->schema([
                        Repeater::make('slides')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('sort')
                            ->columns(2)
                            ->minItems(1)
                            ->addActionLabel('Add slide')
                            ->schema([
                                Select::make('image_id')
                                    ->label('Image')
                                    ->options(fn (): array => self::imageOptions())
                                    ->allowHtml()
                                    ->searchable()
                                    ->required()
                                    ->createOptionForm([
                                        FileUpload::make('file')
                                            ->label('New image')
                                            ->image()
                                            ->acceptedFileTypes(array_keys(Image::TYPES))
                                            ->storeFiles(false)
                                            ->required()
                                            ->helperText('It\'s added to the image library.'),
                                    ])
                                    ->createOptionUsing(fn (array $data): int => Image::storeUpload($data['file'])->id)
                                    ->helperText('The image\'s alt text in the library is used.'),
                                Textarea::make('caption')
                                    ->rows(2)
                                    ->maxLength(255)
                                    ->helperText('Shown over the image. Line breaks are kept. Optional.'),
                                Grid::make(3)
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('caption_position')
                                            ->label('Text position')
                                            ->options(SlideCaptionPosition::class)
                                            ->default(SlideCaptionPosition::Bottom)
                                            ->selectablePlaceholder(false)
                                            ->dehydrateStateUsing(fn (SlideCaptionPosition|string|null $state): SlideCaptionPosition|string => $state ?? SlideCaptionPosition::Bottom)
                                            ->helperText('On phones, a box at the side becomes a banner at the bottom.'),
                                        ColorPicker::make('caption_text_color')
                                            ->label('Text color')
                                            ->hex()
                                            ->regex(ThemeSettings::HEX_PATTERN)
                                            ->placeholder('#ffffff')
                                            ->helperText('Leave empty for white.'),
                                        ColorPicker::make('caption_background_color')
                                            ->label('Background color')
                                            ->rgba()
                                            ->regex(ThemeSettings::RGBA_PATTERN)
                                            ->placeholder('rgba(0, 0, 0, 0.65)')
                                            ->helperText('Leave empty for black at 65%.'),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * Every library image, newest first, shown with a thumbnail.
     *
     * @return array<int, string>
     */
    private static function imageOptions(): array
    {
        return Image::query()
            ->latest()
            ->get()
            ->mapWithKeys(fn (Image $image): array => [
                $image->id => '<span style="display:flex;align-items:center;gap:0.75rem">'
                    .'<img src="'.e($image->url()).'" alt="" loading="lazy" style="width:4rem;height:2.5rem;object-fit:cover;border-radius:0.25rem">'
                    .'<span>'.e($image->filename).'</span></span>',
            ])
            ->all();
    }
}
