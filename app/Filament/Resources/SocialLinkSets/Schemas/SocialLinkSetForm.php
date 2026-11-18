<?php

namespace App\Filament\Resources\SocialLinkSets\Schemas;

use App\Filament\Pages\ThemeSettings;
use App\Filament\Resources\Sliders\Schemas\SliderForm;
use App\Models\SocialLinkSet;
use App\SocialLogoStyle;
use App\SocialService;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class SocialLinkSetForm
{
    public const LINKEDIN_DOWNLOADS_URL = 'https://brand.linkedin.com/downloads';

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
                            ->rule(fn (?SocialLinkSet $record): Closure => SocialLinkSet::slugUnchangedWhileInUse($record))
                            ->helperText(fn (Get $get): string => 'Place the links in a page, post, module, card, the footer or the top bar by writing [social:'.($get('slug') ?: 'slug').'] in its content. They sit in the line with the text; add |left, |center or |right, as in [social:'.($get('slug') ?: 'slug').'|center], to give them a line of their own.'),
                        Select::make('logo_style')
                            ->label('Logo colors')
                            ->options(SocialLogoStyle::class)
                            ->default(SocialLogoStyle::Dark)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->helperText('Brand colors shows each logo in its brand\'s own color; Email and Website use the theme\'s link color. Use Light on a dark background. Some brands ask for their logo only in their own color, black or white.'),
                        ColorPicker::make('logo_color')
                            ->label('Custom color')
                            ->hex()
                            ->regex(ThemeSettings::HEX_PATTERN)
                            ->visible(fn (Get $get): bool => self::logoStyle($get) === SocialLogoStyle::Custom)
                            ->required(fn (Get $get): bool => self::logoStyle($get) === SocialLogoStyle::Custom),
                        TextInput::make('logo_size')
                            ->label('Logo size')
                            ->integer()
                            ->default(SocialLinkSet::DEFAULT_LOGO_SIZE)
                            ->minValue(SocialLinkSet::MINIMUM_LOGO_SIZE)
                            ->maxValue(SocialLinkSet::MAXIMUM_LOGO_SIZE)
                            ->suffix('pixels')
                            ->required()
                            ->helperText('Each logo is a square this wide. In the top bar they\'re kept small enough to fit.'),
                        Toggle::make('opens_in_new_tab')
                            ->label('Open links in a new tab')
                            ->default(true)
                            ->inline(false),
                    ]),
                Section::make('Links')
                    ->schema([
                        Repeater::make('links')
                            ->hiddenLabel()
                            ->columns(2)
                            ->minItems(1)
                            ->addActionLabel('Add link')
                            ->itemLabel(fn (array $state): ?string => self::service($state['service'] ?? null)?->title())
                            ->schema([
                                Select::make('service')
                                    ->options(fn (): array => self::serviceOptions())
                                    ->getSearchResultsUsing(fn (string $search): array => self::serviceOptions($search))
                                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::serviceOptions()[$value] ?? null)
                                    ->allowHtml()
                                    ->searchable()
                                    ->required()
                                    ->live(),
                                TextInput::make('url')
                                    ->label(fn (Get $get): string => self::service($get('service')) === SocialService::Email ? 'Email address' : 'Link')
                                    ->placeholder(fn (Get $get): string => self::service($get('service')) === SocialService::Email ? 'you@example.com' : 'https://')
                                    ->required()
                                    ->maxLength(2048)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                        if (self::service($get('service')) === SocialService::Email && $state !== null) {
                                            $set('url', trim((string) preg_replace('/^mailto:/i', '', $state)));
                                        }
                                    })
                                    ->rules(fn (Get $get): array => self::service($get('service')) === SocialService::Email
                                        ? ['email']
                                        : ['url:http,https'])
                                    ->validationMessages([
                                        'url' => 'Enter the full address, starting with https://',
                                    ]),
                                Callout::make('LinkedIn needs its own logo file.')
                                    ->info()
                                    ->description(new HtmlString(self::linkedInNotice()))
                                    ->columnSpanFull()
                                    ->visible(fn (Get $get): bool => self::service($get('service'))?->usesOwnLogo() ?? false),
                                Select::make('image_id')
                                    ->label('LinkedIn logo')
                                    ->options(fn (): array => SliderForm::imageOptions())
                                    ->getSearchResultsUsing(fn (string $search): array => SliderForm::imageSearchResults($search))
                                    ->getOptionLabelUsing(fn (mixed $value): ?string => SliderForm::imageOptionLabel($value))
                                    ->allowHtml()
                                    ->searchable()
                                    ->columnSpanFull()
                                    ->visible(fn (Get $get): bool => self::service($get('service'))?->usesOwnLogo() ?? false)
                                    ->required(fn (Get $get): bool => self::service($get('service'))?->usesOwnLogo() ?? false)
                                    ->helperText(SliderForm::imageSearchHelp()),
                            ]),
                    ]),
            ]);
    }

    /**
     * What a LinkedIn link needs, shown when LinkedIn is chosen.
     */
    public static function linkedInNotice(): string
    {
        return 'LinkedIn only allows its official logo, downloaded from LinkedIn, so it can\'t be built in like the others.'
            .'<ol style="list-style: decimal; margin: 0.5rem 0 0.5rem 1.25rem">'
            .'<li>Download the logo from <a href="'.self::LINKEDIN_DOWNLOADS_URL.'" target="_blank" rel="noopener" style="text-decoration: underline">brand.linkedin.com/downloads</a>. (Downloading it means you accept LinkedIn\'s brand rules.)</li>'
            .'<li>Choose the blue, black or white version, whichever shows up best against the background where these links will appear.</li>'
            .'<li>Upload it on the Images screen, then pick it below.</li>'
            .'</ol>'
            .'LinkedIn allows only its own colors and doesn\'t allow the logo to be changed, so the logo style chosen at the top of this screen doesn\'t apply to it.';
    }

    /**
     * Every service with its logo beside its name, or those whose name
     * contains the search. The search looks at names only, not the logos'
     * drawing code.
     *
     * @return array<string, string>
     */
    public static function serviceOptions(?string $search = null): array
    {
        return collect(SocialService::cases())
            ->filter(fn (SocialService $service): bool => blank($search) || Str::contains($service->getLabel(), $search, ignoreCase: true))
            ->mapWithKeys(fn (SocialService $service): array => [
                $service->value => '<span style="display:flex;align-items:center;gap:0.6rem">'
                    .'<span style="display:inline-flex;width:1.25rem;height:1.25rem;flex:none">'.str_replace('<svg ', '<svg width="100%" height="100%" ', $service->logoSvg() ?? '').'</span>'
                    .'<span>'.e($service->getLabel()).'</span></span>',
            ])
            ->all();
    }

    private static function service(mixed $value): ?SocialService
    {
        return $value instanceof SocialService ? $value : SocialService::tryFrom((string) $value);
    }

    private static function logoStyle(Get $get): ?SocialLogoStyle
    {
        $style = $get('logo_style');

        return $style instanceof SocialLogoStyle ? $style : SocialLogoStyle::tryFrom((string) $style);
    }
}
