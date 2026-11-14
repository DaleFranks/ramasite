<?php

namespace App;

use App\Http\Middleware\UseSiteFolder;
use App\Models\Card;
use App\Models\Form;
use App\Models\Image;
use App\Models\LibraryFile;
use App\Models\Module;
use App\Models\Slider;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns Markdown into HTML, filling in shortcodes:
 * - [image:file|width|align|description] places an image from the library (or from a full URL)
 * - [file:name|link text|details] links to a file from the file library, with its type and size after "details"
 * - [youtube:url|width|align] embeds a YouTube video
 * - [form:slug] places a form built on the Forms screen
 * - [module:slug], [card:slug] and [cards: slug-1, slug-2] place modules and cards (pages only)
 * - [slider:slug] places a slider (pages only)
 *
 * Ordinary Markdown images, ![description](team.jpg), also find their file in
 * the library.
 *
 * Each shortcode is swapped for a placeholder before the Markdown is converted
 * and filled in afterwards, so its HTML is never converted twice. Videos,
 * forms, modules, cards and sliders are blocks, so each gets a paragraph of its
 * own: text written on the same line carries on after the block instead of
 * ending up inside it. Images stay in the line, so text written beside them
 * wraps around them. A
 * shortcode naming something that doesn't exist is left as written, so the
 * mistake shows. Inside `code` or a fenced code block, shortcodes are shown
 * as written, so a page can explain how to use them.
 */
class Markdown
{
    private const SHORTCODE_PATTERN = '/\[(image|file|youtube|form|module|card|cards|slider):([^\]]+)\]/i';

    private const MARKDOWN_IMAGE_PATTERN = '/!\[([^\]]*)\]\(\s*<?([^\s)>]+)>?(?:\s+(?:"([^"]*)"|\'([^\']*)\'))?\s*\)/';

    private const PLACEHOLDER_PATTERN = '/<!--shortcode:(\d+)-->/';

    /**
     * Images are held in place by plain text (between two private-use characters)
     * rather than an HTML comment, which Markdown would treat as a block of its
     * own when it starts a line, pulling the image out of its paragraph.
     */
    private const INLINE_PLACEHOLDER_PATTERN = '/\x{E000}(\d+)\x{E001}/u';

    /**
     * Fenced code blocks (``` or ~~~, closed by the same fence) and `code`
     * within a paragraph (never across a blank line).
     */
    private const CODE_PATTERN = '/^ {0,3}(`{3,}|~{3,})[^\n]*\n.*?^ {0,3}\1[ \t]*$|(`+)(?:(?!\n[ \t]*\n).)+?\2(?!`)/ms';

    private const CODE_PLACEHOLDER_PATTERN = '/\x{E002}(\d+)\x{E003}/u';

    /**
     * @param  bool  $withBlocks  Whether modules and cards may be placed. They can't be placed inside each other.
     */
    public static function toHtml(string $markdown, bool $withBlocks = false): string
    {
        /** @var list<array{kind: string, value: string, original: string, image?: array{file: string, size: ?string, align: ?string, description: ?string}}> $shortcodes */
        $shortcodes = [];

        /** @var list<string> $code */
        $code = [];

        // Code is set aside so its shortcodes stay as written, then put back for the converter
        $markdown = preg_replace_callback(self::CODE_PATTERN, function (array $match) use (&$code): string {
            $code[] = $match[0];

            return "\u{E002}".array_key_last($code)."\u{E003}";
        }, $markdown);

        $markdown = preg_replace_callback(self::MARKDOWN_IMAGE_PATTERN, function (array $match) use (&$shortcodes): string {
            if (preg_match('#^(https?:|data:|//)#i', $match[2]) || Image::nameFromReference($match[2]) === null) {
                return $match[0];
            }

            $shortcodes[] = [
                'kind' => 'image',
                'value' => $match[2],
                'original' => $match[0],
                'image' => ['file' => $match[2], 'size' => null, 'align' => null, 'description' => trim($match[1]) ?: null],
            ];

            return self::inlinePlaceholder(array_key_last($shortcodes));
        }, $markdown);

        $markdown = preg_replace_callback(self::SHORTCODE_PATTERN, function (array $match) use (&$shortcodes, $withBlocks): string {
            $kind = strtolower($match[1]);

            if (! $withBlocks && ! in_array($kind, ['image', 'file', 'youtube', 'form'], true)) {
                return $match[0];
            }

            $shortcode = ['kind' => $kind, 'value' => trim($match[2]), 'original' => $match[0]];

            if ($kind === 'image') {
                $shortcodes[] = [...$shortcode, 'image' => self::imageOptions($shortcode['value'])];

                return self::inlinePlaceholder(array_key_last($shortcodes));
            }

            if ($kind === 'file') {
                $shortcodes[] = $shortcode;

                return self::inlinePlaceholder(array_key_last($shortcodes));
            }

            $shortcodes[] = $shortcode;

            return "\n\n<!--shortcode:".array_key_last($shortcodes)."-->\n\n";
        }, $markdown);

        if ($code !== []) {
            $markdown = preg_replace_callback(self::CODE_PLACEHOLDER_PATTERN, fn (array $match): string => $code[(int) $match[1]], $markdown);
        }

        $html = self::inSiteFolder(Str::markdown($markdown, [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]));

        if ($shortcodes === []) {
            return $html;
        }

        $html = preg_replace(self::INLINE_PLACEHOLDER_PATTERN, '<!--shortcode:$1-->', $html);

        $modules = self::load(Module::class, $shortcodes, ['module']);
        $cards = self::load(Card::class, $shortcodes, ['card', 'cards']);
        $sliders = self::load(Slider::class, $shortcodes, ['slider']);
        $forms = self::load(Form::class, $shortcodes, ['form']);
        $images = self::loadImages($shortcodes);
        $files = self::loadFiles($shortcodes);

        return preg_replace_callback(self::PLACEHOLDER_PATTERN, function (array $match) use ($shortcodes, $modules, $cards, $sliders, $forms, $images, $files): string {
            $shortcode = $shortcodes[(int) $match[1]] ?? null;

            if ($shortcode === null) {
                return $match[0];
            }

            $html = match ($shortcode['kind']) {
                'image' => self::image($shortcode['image'], $images),
                'file' => self::fileLink($shortcode['value'], $files),
                'youtube' => self::youtube($shortcode['value']),
                'module' => $modules->get(self::slugs($shortcode['value'])[0] ?? '')?->html(),
                'card' => $cards->get(self::slugs($shortcode['value'])[0] ?? '')?->html(),
                'cards' => self::cardGrid(self::slugs($shortcode['value']), $cards),
                'slider' => $sliders->get(self::slugs($shortcode['value'])[0] ?? '')?->html(),
                'form' => $forms->get(self::slugs($shortcode['value'])[0] ?? '')?->html(),
            };

            if ($html !== null) {
                return $html;
            }

            return in_array($shortcode['kind'], ['image', 'file'], true) ? e($shortcode['original']) : '<p>'.e($shortcode['original']).'</p>';
        }, $html);
    }

    /**
     * Mark each link that is the only thing in its paragraph (a line of its
     * own in the Markdown) as a button, for the panels that show such links
     * as buttons. Links within text are left as they are, and so is a link
     * written in HTML with its own class.
     */
    public static function withButtonLinks(string $html): string
    {
        return (string) preg_replace_callback(
            '#<p>\s*<a\s([^>]*)>((?:(?!</?a[\s>]|</?p>).)*)</a>\s*</p>#s',
            fn (array $match): string => preg_match('/\bclass\s*=/i', $match[1])
                ? $match[0]
                : '<p><a class="button-link" '.$match[1].'>'.$match[2].'</a></p>',
            $html,
        );
    }

    private static function inlinePlaceholder(int $index): string
    {
        return "\u{E000}{$index}\u{E001}";
    }

    /**
     * The slugs named in a shortcode, lowercased and without the ".md" the old CMS allowed.
     *
     * @return list<string>
     */
    public static function slugs(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn (string $slug): string => strtolower(preg_replace('/\.md$/i', '', trim($slug))))
            ->filter(fn (string $slug): bool => (bool) preg_match('/^[a-z0-9-]+$/', $slug))
            ->values()
            ->all();
    }

    /**
     * Load, in one query, every module or card the shortcodes of the given kinds name.
     *
     * @template TModel of Module|Card|Slider|Form
     *
     * @param  class-string<TModel>  $model
     * @param  list<array{kind: string, value: string, original: string}>  $shortcodes
     * @param  list<string>  $kinds
     * @return Collection<string, TModel>
     */
    private static function load(string $model, array $shortcodes, array $kinds): Collection
    {
        $slugs = collect($shortcodes)
            ->filter(fn (array $shortcode): bool => in_array($shortcode['kind'], $kinds, true))
            ->flatMap(fn (array $shortcode): array => self::slugs($shortcode['value']))
            ->unique();

        if ($slugs->isEmpty()) {
            return collect();
        }

        return $model::query()->whereIn('slug', $slugs)->get()->keyBy('slug')->toBase();
    }

    /**
     * Load, in one query, every library image the shortcodes name.
     *
     * @param  list<array{kind: string, value: string, original: string, image?: array{file: string, size: ?string, align: ?string, description: ?string}}>  $shortcodes
     * @return Collection<string, Image>
     */
    private static function loadImages(array $shortcodes): Collection
    {
        $filenames = collect($shortcodes)
            ->filter(fn (array $shortcode): bool => isset($shortcode['image']))
            ->map(fn (array $shortcode): ?string => Image::nameFromReference($shortcode['image']['file']))
            ->filter()
            ->unique();

        if ($filenames->isEmpty()) {
            return collect();
        }

        return Image::query()->whereIn('filename', $filenames)->get()->keyBy('filename')->toBase();
    }

    /**
     * Load, in one query, every library file the shortcodes name.
     *
     * @param  list<array{kind: string, value: string, original: string}>  $shortcodes
     * @return Collection<string, LibraryFile>
     */
    private static function loadFiles(array $shortcodes): Collection
    {
        $filenames = collect($shortcodes)
            ->filter(fn (array $shortcode): bool => $shortcode['kind'] === 'file')
            ->map(fn (array $shortcode): ?string => LibraryFile::nameFromReference(self::fileOptions($shortcode['value'])['file']))
            ->filter()
            ->unique();

        if ($filenames->isEmpty()) {
            return collect();
        }

        return LibraryFile::query()->whereIn('filename', $filenames)->get()->keyBy('filename')->toBase();
    }

    /**
     * The parts of [file:name|link text|details]. After the name, "details"
     * (in lower case) asks for the file's type and size; anything else is
     * the link's text, so a link can read "Details".
     *
     * @return array{file: string, text: ?string, details: bool}
     */
    public static function fileOptions(string $value): array
    {
        $parts = array_map(trim(...), explode('|', $value));
        $options = ['file' => (string) array_shift($parts), 'text' => null, 'details' => false];

        foreach ($parts as $part) {
            if ($part === 'details') {
                $options['details'] = true;
            } elseif ($part !== '') {
                $options['text'] = $part;
            }
        }

        return $options;
    }

    /**
     * A link to a library file, its text being the one given or else the file's
     * name, followed by its type and size when asked for. The size is looked up
     * as the page is shown, so it stays right when the file is replaced.
     *
     * @param  Collection<string, LibraryFile>  $files
     */
    private static function fileLink(string $value, Collection $files): ?string
    {
        $options = self::fileOptions($value);
        $file = $files->get((string) LibraryFile::nameFromReference($options['file']));

        if ($file === null) {
            return null;
        }

        $link = '<a href="'.e($file->url()).'">'.e($options['text'] ?? $file->filename).'</a>';

        return $options['details'] ? $link.' <span class="file-details">('.e($file->details()).')</span>' : $link;
    }

    /**
     * Puts the site's folder in front of addresses written from the site's root,
     * such as [our services](/services), when the site is in a folder (such as
     * /blog), so they lead to /blog/services. At the root nothing changes.
     */
    private static function inSiteFolder(string $html): string
    {
        $folder = UseSiteFolder::path();

        if ($folder === '') {
            return $html;
        }

        return preg_replace_callback('#(\s(?:href|src)=")(/(?!/)[^"]*)#i', fn (array $match): string => str_starts_with($match[2].'/', $folder.'/')
            ? $match[0]
            : $match[1].$folder.$match[2], $html);
    }

    /**
     * The parts of [image:file|width|align|description]. After the file, the parts
     * may come in any order: a width ("400", "50%" or "400x300"), an alignment
     * (left, center or right), and anything else is the description.
     *
     * @return array{file: string, size: ?string, align: ?string, description: ?string}
     */
    private static function imageOptions(string $value): array
    {
        $parts = array_map(trim(...), explode('|', $value));
        $options = ['file' => array_shift($parts), 'size' => null, 'align' => null, 'description' => null];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (self::isImageSize($part)) {
                $options['size'] = strtolower($part);
            } elseif (self::isImageAlignment($part)) {
                $options['align'] = strtolower($part);
            } else {
                $options['description'] = $part;
            }
        }

        return $options;
    }

    /**
     * Whether a part of [image:...] is a width, such as "400", "50%" or "400x300".
     */
    public static function isImageSize(string $part): bool
    {
        return (bool) preg_match('/^\d+(%|x\d+|px)?$/i', trim($part));
    }

    /**
     * Whether a part of [image:...] is an alignment: left, center or right.
     */
    public static function isImageAlignment(string $part): bool
    {
        return in_array(strtolower(trim($part)), ['left', 'center', 'right'], true);
    }

    /**
     * An <img> for a library image, or for a full image URL.
     *
     * Without a description, a library image uses the description saved with it.
     * The width and height are always given when known, so the page doesn't jump
     * about as images load.
     *
     * @param  array{file: string, size: ?string, align: ?string, description: ?string}  $options
     * @param  Collection<string, Image>  $images
     */
    private static function image(array $options, Collection $images): ?string
    {
        $isRemote = (bool) preg_match('#^https?://#i', $options['file']);
        $image = $isRemote ? null : $images->get((string) Image::nameFromReference($options['file']));

        if (! $isRemote && $image === null) {
            return null;
        }

        $attributes = [
            'src' => $image?->url() ?? $options['file'],
            'alt' => $options['description'] ?? $image?->alt_text ?? '',
            'title' => $options['description'],
            'class' => $options['align'] ? 'img-'.$options['align'] : null,
            'width' => $image?->width,
            'height' => $image?->height,
            'style' => null,
            'loading' => 'lazy',
        ];

        $size = (string) $options['size'];

        if (str_ends_with($size, '%')) {
            $attributes['style'] = '--img-width: '.$size;
        } elseif (preg_match('/^(\d+)(?:x(\d+)|px)?$/', $size, $dimensions)) {
            $width = (int) $dimensions[1];
            $attributes['style'] = '--img-width: '.$width.'px';
            $attributes['height'] = isset($dimensions[2])
                ? (int) $dimensions[2]
                : ($image?->width ? (int) round($width * $image->height / $image->width) : null);
            $attributes['width'] = $width;
        }

        return '<img '.collect($attributes)
            ->reject(fn (mixed $value): bool => $value === null)
            ->map(fn (mixed $value, string $name): string => $name.'="'.e((string) $value).'"')
            ->implode(' ').'>';
    }

    /**
     * The named cards side by side, skipping any that don't exist, in as many
     * columns as fill the rows evenly.
     *
     * @param  list<string>  $slugs
     * @param  Collection<string, Card>  $cards
     */
    private static function cardGrid(array $slugs, Collection $cards): ?string
    {
        $gridCards = collect($slugs)->map(fn (string $slug): ?Card => $cards->get($slug))->filter();

        if ($gridCards->isEmpty()) {
            return null;
        }

        // As many columns as fill each row evenly: 4 cards make 2 × 2, 5 or more go 3 across.
        $columns = match ($gridCards->count()) {
            1 => 1,
            2, 4 => 2,
            default => 3,
        };

        return '<div class="card-grid columns-'.$columns.'">'."\n".$gridCards->map->html()->implode("\n")."\n".'</div>';
    }

    /**
     * An embedded YouTube video from [youtube:url|width|align], where width is
     * pixels ("560") or a percentage ("50%") and align is left, center or right.
     */
    private static function youtube(string $value): ?string
    {
        $parts = array_map(trim(...), explode('|', $value));
        $url = array_shift($parts);
        $width = null;
        $align = 'center';

        foreach ($parts as $part) {
            if (in_array(strtolower($part), ['left', 'center', 'right'], true)) {
                $align = strtolower($part);
            } elseif (preg_match('/^(\d+)(px|%)?$/i', $part, $size)) {
                $width = $size[1].strtolower(($size[2] ?? '') ?: 'px');
            }
        }

        $videoId = self::youtubeVideoId($url);

        if ($videoId === null) {
            return null;
        }

        $style = $width === null ? '' : ' style="width: '.$width.'"';

        return '<div class="video-container video-'.$align.'"'.$style.'>'
            .'<iframe src="https://www.youtube.com/embed/'.$videoId.'" title="YouTube video" loading="lazy" '
            .'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
            .'referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>'
            .'</div>';
    }

    /**
     * The video ID from any usual form of YouTube link: watch?v=, youtu.be/,
     * /embed/, /shorts/ or /live/.
     */
    public static function youtubeVideoId(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower(preg_replace('/^(www\.|m\.)/i', '', $parts['host'] ?? ''));
        $path = $parts['path'] ?? '';
        $videoId = null;

        if ($host === 'youtu.be') {
            $videoId = ltrim($path, '/');
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if (preg_match('#^/(embed|shorts|live)/([^/]+)#', $path, $match)) {
                $videoId = $match[2];
            } else {
                parse_str($parts['query'] ?? '', $query);
                $videoId = is_string($query['v'] ?? null) ? $query['v'] : null;
            }
        }

        return $videoId !== null && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId) ? $videoId : null;
    }
}
