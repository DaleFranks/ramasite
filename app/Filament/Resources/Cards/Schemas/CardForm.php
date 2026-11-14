<?php

namespace App\Filament\Resources\Cards\Schemas;

use App\BoxStyle;
use App\Models\Card;
use App\TextAlignment;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CardForm
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
                            ->rule(fn (?Card $record): Closure => Card::slugUnchangedWhileInUse($record))
                            ->helperText(fn (Get $get): string => 'Place the card in a page by writing [card:'.($get('slug') ?: 'slug').'] in its content, or show several side by side with [cards: first-slug, second-slug].'),
                        Select::make('style')
                            ->options(BoxStyle::class)
                            ->placeholder('None')
                            ->helperText('Shows the card as a colored panel.'),
                        Select::make('text_alignment')
                            ->label('Text alignment')
                            ->options(TextAlignment::class)
                            ->placeholder('Style\'s default')
                            ->helperText('Dark and Primary color cards are centered by default. Light and plain cards are left-aligned.'),
                        Toggle::make('featured')
                            ->helperText('Highlights the card with a colored border and a "Featured" ribbon.'),
                        MarkdownEditor::make('body')
                            ->label('Content'),
                    ]),
            ]);
    }
}
