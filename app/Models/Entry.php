<?php

namespace App\Models;

use App\BlogColumns;
use App\EntryLayout;
use App\EntryStatus;
use App\EntryType;
use App\Markdown;
use App\SiteAddress;
use Database\Factories\EntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A page, blog or blog post, written in Markdown.
 *
 * A blog is a page that lists its posts. Each post belongs to one blog, and its
 * path is the blog's path followed by the post's own slug, e.g. "news/v1-released".
 */
#[Fillable([
    'type',
    'title',
    'slug',
    'blog_id',
    'body',
    'layout',
    'show_title',
    'status',
    'published_at',
    'nav_order',
    'menu_label',
    'posts_per_page',
    'posts_columns',
    'parent_id',
    'meta_description',
    'meta_keywords',
    'featured_image',
    'author_id',
])]
class Entry extends Model
{
    /**
     * The path of the entry shown at the site's root address.
     */
    public const HOME_PATH = 'home';

    /**
     * How many posts a new blog lists per page: three full rows of three.
     */
    public const DEFAULT_POSTS_PER_PAGE = 9;

    /**
     * Paths no entry may take: those used by the app itself, and the folders
     * in public/, which the web server answers before the CMS sees the visit.
     * The site's top-level folders aren't here: the root .htaccess sends
     * those addresses to the CMS.
     *
     * @var list<string>
     */
    public const RESERVED_PATHS = ['admin', 'css', 'filament', 'fonts', 'forms', 'js', 'livewire', 'setup', 'storage', 'up', 'uploads'];

    /** @use HasFactory<EntryFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'layout' => 'standard',
        'posts_columns' => 'automatic',
    ];

    protected static function booted(): void
    {
        static::saving(function (Entry $entry): void {
            if ($entry->type !== EntryType::Post) {
                $entry->blog_id = null;
            }

            $entry->path = $entry->buildPath();
        });

        static::saved(function (Entry $entry): void {
            if ($entry->type === EntryType::Blog && $entry->wasChanged('path')) {
                $entry->posts()->each(fn (Entry $post): bool => $post->save());
            }
        });

        // A published page that moves leaves a redirect behind, so old links and
        // search results still find it.
        static::updated(function (Entry $entry): void {
            if ($entry->wasChanged('path') && $entry->getRawOriginal('status') === EntryStatus::Published->value) {
                Redirect::forMovedPage((string) $entry->getRawOriginal('path'), $entry->path);
            }
        });

        static::deleting(function (Entry $entry): bool {
            return ! $entry->posts()->exists();
        });
    }

    /**
     * Saved in one transaction, because a blog that moves re-saves its posts:
     * if one of them can't move, the blog and every post stay where they were.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => parent::save($options));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EntryType::class,
            'status' => EntryStatus::class,
            'layout' => EntryLayout::class,
            'show_title' => 'boolean',
            'published_at' => 'datetime',
            'nav_order' => 'integer',
            'posts_per_page' => 'integer',
            'posts_columns' => BlogColumns::class,
        ];
    }

    /**
     * Build the URL path for the given slug, as it would be for this entry.
     */
    public function buildPath(?string $slug = null): string
    {
        $slug ??= $this->slug;

        if ($this->type !== EntryType::Post || $this->blog_id === null) {
            return $slug;
        }

        $blogPath = Entry::query()->whereKey($this->blog_id)->value('path');

        return $blogPath.'/'.$slug;
    }

    /**
     * The address of the entry on the public site.
     */
    public function url(): string
    {
        return $this->path === self::HOME_PATH ? url('/') : url($this->path);
    }

    /**
     * The entry's one true address, for search engines, sharing and feeds:
     * the same as url(), but on the site's main domain (see
     * SiteAddress::canonicalRoot()). The home page's ends in a slash, which a
     * site in a folder needs ("/site" is redirected to "/site/"). A blog's
     * later pages keep their number.
     */
    public function canonicalUrl(int $page = 1): string
    {
        $address = SiteAddress::canonicalRoot().'/'.($this->path === self::HOME_PATH ? '' : $this->path);

        return $page > 1 ? $address.'?page='.$page : $address;
    }

    /**
     * The address of a blog's RSS feed, on the site's main domain.
     */
    public function feedUrl(): string
    {
        return SiteAddress::canonicalRoot().'/'.$this->path.'/feed.xml';
    }

    /**
     * The published blogs, whose feeds every page points to so browsers and
     * feed readers can find them.
     *
     * @return Collection<int, Entry>
     */
    public static function blogsWithFeeds(): Collection
    {
        return self::query()
            ->published()
            ->where('type', EntryType::Blog)
            ->orderBy('title')
            ->get(['id', 'type', 'title', 'path']);
    }

    /**
     * Whether the title shows at the top of the page. Every layout shows it
     * except Landing, which shows it only when the page's switch is on;
     * otherwise it's still there for search engines and screen readers.
     */
    public function showsTitle(): bool
    {
        return $this->layout?->showsTitle() !== false || (bool) $this->show_title;
    }

    /**
     * The body as HTML, with its shortcodes filled in. A leading "# Heading" is
     * dropped, because the page title is already shown above the content.
     */
    public function bodyHtml(): string
    {
        $markdown = preg_replace('/^\s*#\s+.+?(\r?\n)+/', '', (string) $this->body, 1);

        return Markdown::toHtml($markdown, withBlocks: true);
    }

    /**
     * The first three sentences of the body as plain text, for blog feeds.
     */
    public function excerpt(): string
    {
        // A file link reads as its text, e.g. "Download our menu", so the sentence stays whole
        $markdown = preg_replace_callback('/\[file:([^\]]+)\]/i', function (array $match): string {
            $options = Markdown::fileOptions($match[1]);

            return $options['text'] ?? $options['file'];
        }, (string) $this->body);

        $markdown = preg_replace([
            '/!\[.*?\]\(.*?\)/',
            '/^#+\s+.*$/m',
            '/\[(module|card|cards|youtube|image|slider|form):[^\]]*\]/i',
        ], '', $markdown);

        $text = trim(html_entity_decode(strip_tags(Str::markdown($markdown)), ENT_QUOTES, 'UTF-8'));
        $sentences = preg_split('/(?<=[.?!])\s+/u', $text) ?: [];

        return implode(' ', array_slice($sentences, 0, 3));
    }

    /**
     * The files or addresses of the images in some content, in the order they
     * appear, placed with [image:...] or ![description](...).
     *
     * @return list<string>
     */
    public static function imageReferencesIn(?string $body): array
    {
        preg_match_all('/\[image:\s*([^|\]]+)|!\[[^\]]*\]\(\s*([^)\s]+)/i', (string) $body, $matches, PREG_SET_ORDER);

        return array_values(array_filter(array_map(
            fn (array $match): string => trim($match[2] ?? '') ?: trim($match[1]),
            $matches,
        )));
    }

    /**
     * The library images some content uses, in the order they first appear,
     * keyed by file name: the choices for a page's featured image.
     *
     * @return \Illuminate\Support\Collection<string, Image>
     */
    public static function libraryImagesIn(?string $body): \Illuminate\Support\Collection
    {
        $names = collect(self::imageReferencesIn($body))
            ->reject(fn (string $reference): bool => (bool) preg_match('#^https?://#i', $reference))
            ->map(fn (string $reference): ?string => Image::nameFromReference($reference))
            ->filter()
            ->unique()
            ->values();

        $images = Image::query()->whereIn('filename', $names)->get()->keyBy('filename');

        return $names->filter(fn (string $name): bool => $images->has($name))
            ->mapWithKeys(fn (string $name): array => [$name => $images->get($name)]);
    }

    /**
     * The image that stands for this page or post when it's shared and in its
     * blog's list: the featured image chosen for it, as long as the content
     * still uses it, otherwise the first image in the content.
     */
    public function featuredImageReference(): ?string
    {
        $references = self::imageReferencesIn($this->body);

        if (filled($this->featured_image)) {
            foreach ($references as $reference) {
                if (Image::nameFromReference($reference) === $this->featured_image) {
                    return $reference;
                }
            }
        }

        return $references[0] ?? null;
    }

    /**
     * The picture offered to social media when this page is shared, as a full
     * address with its size when known, or nothing if the content has none.
     *
     * @return array{url: string, width: ?int, height: ?int}|null
     */
    public function shareImage(): ?array
    {
        $reference = $this->featuredImageReference();

        if ($reference === null) {
            return null;
        }

        if (preg_match('#^https?://#i', $reference)) {
            return ['url' => $reference, 'width' => null, 'height' => null];
        }

        $image = Image::query()->where('filename', Image::nameFromReference($reference))->first();

        return $image === null ? null : [
            'url' => request()->getSchemeAndHttpHost().$image->url(),
            'width' => $image->width ?: null,
            'height' => $image->height ?: null,
        ];
    }

    /**
     * The address of each post's featured (or else first) image, for the
     * pictures in a blog feed, keyed by post id. Library images are found in
     * one query; a post whose image isn't in the library (or has none) is left out.
     *
     * @param  iterable<Entry>  $posts
     * @return array<int, string>
     */
    public static function previewImageUrls(iterable $posts): array
    {
        $references = collect($posts)
            ->mapWithKeys(fn (Entry $post): array => [$post->id => $post->featuredImageReference()])
            ->filter();

        $images = Image::query()
            ->whereIn('filename', $references->map(fn (string $reference): ?string => Image::nameFromReference($reference))->filter()->unique()->values())
            ->get()
            ->keyBy('filename');

        return $references
            ->map(fn (string $reference): ?string => preg_match('#^https?://#i', $reference)
                ? $reference
                : $images->get((string) Image::nameFromReference($reference))?->url())
            ->filter()
            ->all();
    }

    /**
     * Published pages and blogs that are in the menu, with their children
     * nested under them in menu order.
     *
     * @return Collection<int, Entry>
     */
    public static function menu(): Collection
    {
        $items = self::query()
            ->published()
            ->whereNot('type', EntryType::Post)
            ->whereNotNull('nav_order')
            ->orderBy('nav_order')
            ->orderBy('title')
            ->get(['id', 'type', 'title', 'menu_label', 'path', 'parent_id', 'nav_order']);

        $itemsById = $items->keyBy('id');
        $childrenByParent = $items->groupBy('parent_id');

        foreach ($items as $item) {
            $item->setRelation('children', $childrenByParent->get($item->id, new Collection));
        }

        return $items
            ->filter(fn (Entry $item): bool => $item->parent_id === null || ! $itemsById->has($item->parent_id))
            ->values();
    }

    /**
     * The entry's name in menus: its short menu label if it has one, otherwise its title.
     */
    public function menuLabel(): string
    {
        return filled($this->menu_label) ? $this->menu_label : $this->title;
    }

    /**
     * Whether this menu item, or anything under it, is the given path.
     */
    public function isActiveFor(string $path): bool
    {
        return $this->path === $path
            || $this->children->contains(fn (Entry $child): bool => $child->isActiveFor($path));
    }

    /**
     * The blog a post belongs to.
     *
     * @return BelongsTo<Entry, $this>
     */
    public function blog(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'blog_id');
    }

    /**
     * The posts in a blog.
     *
     * @return HasMany<Entry, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Entry::class, 'blog_id');
    }

    /**
     * The user who wrote the entry.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The entry this one sits under in the navigation menu.
     *
     * @return BelongsTo<Entry, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'parent_id');
    }

    /**
     * The entries that sit under this one in the navigation menu.
     *
     * @return HasMany<Entry, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Entry::class, 'parent_id');
    }

    /**
     * The ids of everything under this entry: its children, theirs in turn,
     * and a blog's posts. None of them can be its parent, or the menu would
     * loop and leave them all out.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $found = [];
        $level = [$this->getKey()];

        while ($level !== []) {
            $level = Entry::query()
                ->where(fn (Builder $query) => $query->whereIn('parent_id', $level)->orWhereIn('blog_id', $level))
                ->whereKeyNot([$this->getKey(), ...$found])
                ->pluck('id')
                ->all();
            $found = [...$found, ...$level];
        }

        return $found;
    }

    /**
     * Whether the entry is published and its publish date has arrived: the
     * published() scope's test, for an entry that's already loaded.
     */
    public function isPublished(): bool
    {
        return $this->status === EntryStatus::Published
            && ($this->published_at === null || $this->published_at->lte(now()));
    }

    /**
     * Whether visitors can see the entry on the site: it's published, and so
     * is the blog of a post.
     */
    public function isVisibleToVisitors(): bool
    {
        if (! $this->isPublished()) {
            return false;
        }

        return $this->type !== EntryType::Post || $this->blog?->isPublished() === true;
    }

    /**
     * What a signed-in admin is told when viewing an entry visitors can't see
     * yet, or nothing when visitors can see it.
     */
    public function previewNotice(): ?string
    {
        if ($this->isVisibleToVisitors()) {
            return null;
        }

        $kind = strtolower($this->type->getLabel());

        if ($this->status === EntryStatus::Draft) {
            return "This {$kind} is a draft, so visitors can't see it.";
        }

        if (! $this->isPublished()) {
            $appearsAt = Theme::localTime($this->published_at)->format('F j, Y \\a\\t g:i a');

            return "This {$kind} will appear on the site on {$appearsAt}.";
        }

        return "This post's blog isn't on the site yet, so visitors can't see the post.";
    }

    /**
     * Only entries that are published and whose publish date has arrived.
     */
    #[Scope]
    protected function published(Builder $query): Builder
    {
        return $query->where('status', EntryStatus::Published)
            ->where(function (Builder $query): void {
                $query->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }
}
