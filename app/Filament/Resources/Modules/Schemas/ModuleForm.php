<?php

namespace App\Filament\Resources\Modules\Schemas;

use App\BandEdge;
use App\BoxStyle;
use App\Filament\Resources\Sliders\Schemas\SliderForm;
use App\Models\Module;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ModuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
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
                            ->rule(fn (?Module $record): Closure => Module::slugUnchangedWhileInUse($record))
                            ->disabled(fn (?Module $record): bool => $record?->isFooter() ?? false)
                            ->helperText(fn (Get $get, ?Module $record): string => $record?->isFooter()
                                ? 'This module is the site footer, shown at the bottom of every page.'
                                : 'Place the module in a page by writing [module:'.($get('slug') ?: 'slug').'] in its content.'),
                        Select::make('style')
                            ->options(BoxStyle::class)
                            ->placeholder('None')
                            ->helperText('Shows the module as a colored panel.')
                            ->hidden(fn (?Module $record): bool => $record?->isFooter() ?? false),
                        Select::make('background_image_id')
                            ->label('Background image')
                            ->options(fn (): array => SliderForm::imageOptions())
                            ->getSearchResultsUsing(fn (string $search): array => SliderForm::imageSearchResults($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => SliderForm::imageOptionLabel($value))
                            ->allowHtml()
                            ->searchable()
                            ->placeholder('None')
                            ->helperText('Fills the module behind its text, cropped to fit. The text color comes from the style. '.SliderForm::imageSearchHelp())
                            ->hidden(fn (?Module $record): bool => $record?->isFooter() ?? false),
                        Grid::make(2)
                            ->hidden(fn (?Module $record): bool => $record?->isFooter() ?? false)
                            ->schema([
                                Select::make('top_edge')
                                    ->label('Top edge')
                                    ->options(BandEdge::class)
                                    ->default(BandEdge::None)
                                    ->selectablePlaceholder(false)
                                    ->helperText('Only on the Landing layout. The shape cuts into the band, with a space above it.'),
                                Select::make('bottom_edge')
                                    ->label('Bottom edge')
                                    ->options(BandEdge::class)
                                    ->default(BandEdge::None)
                                    ->selectablePlaceholder(false)
                                    ->helperText('Only on the Landing layout. The shape cuts into the band, with a space below it.'),
                            ]),
                        MarkdownEditor::make('body')
                            ->label('Content')
                            ->helperText('Shortcodes: [navigation:flat] shows a row of menu links, [navigation:multi] shows a column per menu item with its sub-pages, [curr-year] shows the current year and [site-name] shows the site name.'),
                    ]),
            ]);
    }
}
