<?php

namespace App\Filament\Resources\Entries\Schemas;

use App\BlogColumns;
use App\EntryLayout;
use App\EntryStatus;
use App\EntryType;
use App\Models\Entry;
use App\Models\Image;
use App\Models\Theme;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Component;

class EntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make()
                    ->columnSpan(2)
                    ->schema([
                        Section::make()
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (string $operation, Get $get, Set $set, ?Entry $record) => self::fillSlug($operation, $get, $set, $record)),
                                Hidden::make('slug_is_manual')
                                    ->default(false)
                                    ->dehydrated(false),
                                TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (?string $state, Set $set, Component $livewire, TextInput $component): void {
                                        $set('slug_is_manual', filled($state));
                                        $livewire->validateOnly($component->getStatePath());
                                    })
                                    ->prefix(fn (Get $get): ?string => self::blogPathPrefix($get))
                                    ->regex(fn (Get $get): string => self::isPost($get)
                                        ? '/^[a-z0-9]+(?:-[a-z0-9]+)*$/'
                                        : '/^[a-z0-9]+(?:[-\/][a-z0-9]+)*$/')
                                    ->validationMessages([
                                        'regex' => 'Use lowercase letters, numbers and hyphens only. Pages and blogs may also use slashes.',
                                    ])
                                    ->rule(fn (Get $get, ?Entry $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                        $path = self::pathFor($get, (string) $value);

                                        if (in_array(Str::before($path, '/'), Entry::RESERVED_PATHS, true)) {
                                            $fail('Addresses starting with "'.Str::before($path, '/').'" are used by the system.');

                                            return;
                                        }

                                        if (self::isPathTaken($path, $record)) {
                                            $fail("The address /{$path} is already used by another entry.");

                                            return;
                                        }

                                        $clashingPostPath = self::clashingPostPath($path, $record);

                                        if ($clashingPostPath !== null) {
                                            $fail("A post in this blog would move to /{$clashingPostPath}, which is already used by another entry.");
                                        }
                                    })
                                    ->disabled(fn (?Entry $record): bool => $record?->isHomePage() ?? false)
                                    ->helperText(fn (Get $get, ?Entry $record): string => match (true) {
                                        $record?->isHomePage() ?? false => 'This page is the home page, shown at the site\'s main address, so its slug can\'t change.',
                                        self::isPost($get) => 'The last part of the post\'s address, e.g. "my-first-post".',
                                        default => 'The address of the page, e.g. "about" or "services/design".',
                                    }),
                                MarkdownEditor::make('body')
                                    ->label('Content')
                                    // Sent when the editor loses focus, so the featured image choices follow the content
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        if (! Entry::libraryImagesIn($get('body'))->has((string) $get('featured_image'))) {
                                            $set('featured_image', null);
                                        }
                                    }),
                            ]),
                        Section::make('Search engines & sharing')
                            ->collapsible()
                            ->schema([
                                TextInput::make('meta_description')
                                    ->maxLength(255),
                                TextInput::make('meta_keywords')
                                    ->maxLength(255),
                                Select::make('featured_image')
                                    ->label('Featured image')
                                    ->native(false)
                                    ->allowHtml()
                                    ->placeholder('The first image in the content')
                                    ->options(fn (Get $get): array => self::featuredImageOptions($get('body')))
                                    ->afterStateHydrated(function (Select $component, ?string $state, Get $get): void {
                                        if (filled($state) && ! Entry::libraryImagesIn($get('body'))->has($state)) {
                                            $component->state(null);
                                        }
                                    })
                                    ->helperText('The picture shown when this page is shared on social media (and, for a post, in its blog\'s list). Choose from the images in this page\'s own content; images in cards, modules and sliders aren\'t included. Leave it empty to use the first one.'),
                            ]),
                    ]),
                Group::make()
                    ->schema([
                        Section::make('Publishing')
                            ->schema([
                                Select::make('type')
                                    ->options(EntryType::class)
                                    ->default(fn (): EntryType => self::typeFromAddress())
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (string $operation, Get $get, Set $set, ?Entry $record): void {
                                        self::fillSlug($operation, $get, $set, $record);

                                        // On a new entry, the switch follows the type: on for posts, off for pages and blogs
                                        if ($operation === 'create') {
                                            $set('show_publish_date', Entry::showsPublishDateByDefault(self::type($get)));
                                        }
                                    })
                                    ->disabled(fn (?Entry $record): bool => ($record?->isHomePage() ?? false) || ($record?->type === EntryType::Blog && $record->posts()->exists()))
                                    ->helperText(fn (?Entry $record): ?string => match (true) {
                                        $record?->isHomePage() ?? false => 'The home page can\'t change type.',
                                        $record?->type === EntryType::Blog && $record->posts()->exists() => 'A blog with posts can\'t change type.',
                                        default => null,
                                    }),
                                Select::make('blog_id')
                                    ->label('Blog')
                                    ->relationship(
                                        'blog',
                                        'title',
                                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('type', EntryType::Blog),
                                    )
                                    ->visible(fn (Get $get): bool => self::isPost($get))
                                    ->required(fn (Get $get): bool => self::isPost($get))
                                    ->live()
                                    ->afterStateUpdated(fn (string $operation, Get $get, Set $set, ?Entry $record) => self::fillSlug($operation, $get, $set, $record))
                                    ->preload(),
                                Select::make('status')
                                    ->options(EntryStatus::class)
                                    ->default(EntryStatus::Draft)
                                    ->required()
                                    ->helperText(fn (?Entry $record): ?string => ($record?->isHomePage() ?? false)
                                        ? 'While the home page is a draft, visitors to the site\'s main address see "Page not found".'
                                        : null),
                                DateTimePicker::make('published_at')
                                    ->label('Publish date')
                                    ->helperText('Filled in when it\'s published. Set a future date to schedule it.')
                                    ->timezone(fn (): string => Theme::siteTimezone()),
                                Toggle::make('show_publish_date')
                                    ->label('Show the publish date')
                                    ->default(fn (): bool => Entry::showsPublishDateByDefault(self::typeFromAddress()))
                                    ->formatStateUsing(fn (?bool $state, ?Entry $record): bool => $state ?? Entry::showsPublishDateByDefault($record?->type))
                                    ->helperText('Under the title, on the site. A post shows it in its blog\'s list too. Posts show it unless switched off; pages don\'t unless switched on.'),
                            ]),
                        Section::make('Layout')
                            ->schema([
                                Select::make('layout')
                                    ->hiddenLabel()
                                    ->options(EntryLayout::class)
                                    ->default(EntryLayout::Standard)
                                    ->selectablePlaceholder(false)
                                    ->required()
                                    ->live()
                                    ->helperText(fn (Get $get): ?string => self::layout($get)?->getDescription()),
                                Toggle::make('show_title')
                                    ->label('Show title')
                                    ->helperText('Shows the title at the top of the page, above the content. When off, it\'s still there for search engines and screen readers.')
                                    ->visible(fn (Get $get): bool => self::layout($get) === EntryLayout::Landing),
                            ]),
                        Section::make('Blog')
                            ->visible(fn (Get $get): bool => self::isBlog($get))
                            ->schema([
                                TextInput::make('posts_per_page')
                                    ->label('Posts per page')
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(100)
                                    ->default(Entry::DEFAULT_POSTS_PER_PAGE)
                                    ->required(fn (Get $get): bool => self::isBlog($get))
                                    ->helperText('How many posts the blog lists before going on to a next page. Multiples of 3 fill the rows evenly.'),
                                Select::make('posts_columns')
                                    ->label('Columns')
                                    ->options(BlogColumns::class)
                                    ->default(BlogColumns::Automatic)
                                    ->selectablePlaceholder(false)
                                    ->required(fn (Get $get): bool => self::isBlog($get))
                                    ->helperText('How many columns of posts to show. On smaller screens, 3 columns become 2, and phones always show 1.'),
                            ]),
                        Section::make('Menu')
                            ->hidden(fn (Get $get): bool => self::isPost($get))
                            ->schema([
                                Select::make('parent_id')
                                    ->label('Parent')
                                    ->relationship(
                                        'parent',
                                        'title',
                                        modifyQueryUsing: fn (Builder $query, ?Entry $record) => $query->whereKeyNot($record?->descendantIds() ?? []),
                                        ignoreRecord: true,
                                    )
                                    ->searchable()
                                    ->preload(),
                                TextInput::make('nav_order')
                                    ->label('Menu order')
                                    ->integer()
                                    ->minValue(0)
                                    ->helperText('Leave empty to keep it out of the menu.'),
                                TextInput::make('menu_label')
                                    ->label('Menu label')
                                    ->maxLength(60)
                                    ->placeholder(fn (Get $get): ?string => $get('title'))
                                    ->helperText('A shorter name for the menu. Leave empty to use the title.'),
                            ]),
                    ]),
            ]);
    }

    /**
     * The images in the content as choices for the featured image, each with
     * a small preview.
     *
     * @return array<string, string>
     */
    private static function featuredImageOptions(?string $body): array
    {
        return Entry::libraryImagesIn($body)
            ->map(fn (Image $image, string $name): string => '<span style="display: inline-flex; align-items: center; gap: 0.5rem;">'
                .'<img src="'.e($image->url()).'" alt="" style="width: 2.5rem; height: 2.5rem; object-fit: cover; border-radius: 0.25rem;">'
                .e($name).'</span>')
            ->all();
    }

    /**
     * While creating an entry, fill the slug from the title, numbering it
     * ("welcome-2") if the address is taken. Stops once the slug is typed by hand.
     */
    private static function fillSlug(string $operation, Get $get, Set $set, ?Entry $record): void
    {
        if ($operation !== 'create' || $get('slug_is_manual')) {
            return;
        }

        $baseSlug = Str::slug($get('title') ?? '');

        if ($baseSlug === '') {
            $set('slug', null);

            return;
        }

        $slug = $baseSlug;

        for ($number = 2; self::isPathTaken(self::pathFor($get, $slug), $record); $number++) {
            $slug = "{$baseSlug}-{$number}";
        }

        $set('slug', $slug);
    }

    /**
     * The full address an entry would have with the form's current type and blog.
     */
    private static function pathFor(Get $get, string $slug): string
    {
        $entry = new Entry([
            'type' => $get('type'),
            'blog_id' => self::isPost($get) ? $get('blog_id') : null,
        ]);

        return $entry->buildPath($slug);
    }

    private static function isPathTaken(string $path, ?Entry $record): bool
    {
        return Entry::query()
            ->where('path', $path)
            ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
            ->exists();
    }

    /**
     * When a blog moves to a new address its posts move with it. The first of
     * their new addresses that another entry already uses, if any.
     */
    private static function clashingPostPath(string $path, ?Entry $record): ?string
    {
        if ($record?->type !== EntryType::Blog || $path === $record->path) {
            return null;
        }

        $newPostPaths = $record->posts()->pluck('slug')->map(fn (string $slug): string => $path.'/'.$slug);

        return Entry::query()
            ->whereIn('path', $newPostPaths)
            ->where(fn (Builder $query) => $query->whereNull('blog_id')->orWhere('blog_id', '!=', $record->getKey()))
            ->value('path');
    }

    /**
     * The type asked for in the address, as the Quick actions' "New post" does, or else a page.
     */
    private static function typeFromAddress(): EntryType
    {
        return EntryType::tryFrom((string) request()->query('type')) ?? EntryType::Page;
    }

    private static function type(Get $get): ?EntryType
    {
        $type = $get('type');

        return $type instanceof EntryType ? $type : EntryType::tryFrom((string) $type);
    }

    private static function isPost(Get $get): bool
    {
        $type = $get('type');

        return $type === EntryType::Post || $type === EntryType::Post->value;
    }

    private static function isBlog(Get $get): bool
    {
        $type = $get('type');

        return $type === EntryType::Blog || $type === EntryType::Blog->value;
    }

    private static function layout(Get $get): ?EntryLayout
    {
        $layout = $get('layout');

        return $layout instanceof EntryLayout ? $layout : EntryLayout::tryFrom((string) $layout);
    }

    private static function blogPathPrefix(Get $get): ?string
    {
        if (! self::isPost($get) || blank($get('blog_id'))) {
            return null;
        }

        $blogPath = Entry::query()->whereKey($get('blog_id'))->value('path');

        return $blogPath === null ? null : '/'.$blogPath.'/';
    }
}
