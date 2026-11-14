<?php

namespace App\Filament\Resources\Forms\Schemas;

use App\FormFieldType;
use App\Models\Form;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class FormForm
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
                            ->rule(fn (?Form $record): Closure => Form::slugUnchangedWhileInUse($record))
                            ->helperText(fn (Get $get): string => 'Place the form in a page, module or card by writing [form:'.($get('slug') ?: 'slug').'] in its content.'),
                        TextInput::make('submit_label')
                            ->label('Button wording')
                            ->default('Send')
                            ->required()
                            ->maxLength(60),
                        Textarea::make('success_message')
                            ->label('Thank-you message')
                            ->default('Thank you! Your message has been sent.')
                            ->helperText('Shown in place of the form once it has been sent.')
                            ->required()
                            ->rows(2),
                        TagsInput::make('notify_emails')
                            ->label('Email submissions to')
                            ->placeholder('Type an address and press Enter')
                            ->nestedRecursiveRules(['email'])
                            ->helperText('Each submission is emailed to these addresses. Leave empty to only keep submissions here. How email is sent is set on the Email screen.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Fields')
                    ->schema([
                        Repeater::make('fields')
                            ->hiddenLabel()
                            ->columns(2)
                            ->minItems(1)
                            ->addActionLabel('Add field')
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null)
                                ? $state['label'].(($state['required'] ?? false) ? ' *' : '')
                                : null)
                            ->schema([
                                Hidden::make('key'),
                                TextInput::make('label')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true),
                                Select::make('type')
                                    ->options(FormFieldType::class)
                                    ->default(FormFieldType::Text)
                                    ->selectablePlaceholder(false)
                                    ->required()
                                    ->live(),
                                TagsInput::make('choices')
                                    ->placeholder('Type a choice and press Enter')
                                    ->reorderable()
                                    ->columnSpanFull()
                                    ->visible(fn (Get $get): bool => self::type($get)?->hasChoices() ?? false)
                                    ->required(fn (Get $get): bool => self::type($get)?->hasChoices() ?? false),
                                TextInput::make('help')
                                    ->label('Help text')
                                    ->helperText('Shown under the field. Optional.')
                                    ->maxLength(255),
                                Toggle::make('required')
                                    ->inline(false),
                            ]),
                    ]),
            ]);
    }

    private static function type(Get $get): ?FormFieldType
    {
        $type = $get('type');

        return $type instanceof FormFieldType ? $type : FormFieldType::tryFrom((string) $type);
    }
}
