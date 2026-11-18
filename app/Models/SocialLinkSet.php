<?php

namespace App\Models;

use App\Models\Concerns\PlacedByShortcode;
use App\SocialLogoStyle;
use App\SocialService;
use Database\Factories\SocialLinkSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A row of social media logos, each linking to one of the site's profiles,
 * built on the Social links screen and placed with [social:slug] in a page,
 * post, module, card, the footer or the top bar.
 *
 * Its links are stored in order, each as {service, url, image_id}. Only a
 * LinkedIn link has an image_id: the library image holding LinkedIn's own
 * logo, which the site owner downloads from LinkedIn.
 */
#[Fillable([
    'name',
    'slug',
    'logo_style',
    'logo_color',
    'logo_size',
    'opens_in_new_tab',
    'links',
])]
class SocialLinkSet extends Model
{
    /** @use HasFactory<SocialLinkSetFactory> */
    use HasFactory;

    use PlacedByShortcode;

    public const DEFAULT_LOGO_SIZE = 32;

    /**
     * The smallest logo allowed, so each stays big enough to tap.
     */
    public const MINIMUM_LOGO_SIZE = 24;

    public const MAXIMUM_LOGO_SIZE = 128;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'logo_style' => 'dark',
        'logo_size' => self::DEFAULT_LOGO_SIZE,
        'opens_in_new_tab' => true,
        'links' => '[]',
    ];

    /**
     * @return list<string>
     */
    protected static function placingShortcodes(): array
    {
        return ['social'];
    }

    /**
     * Social links can be placed in modules and cards (and so the footer) as
     * well as pages and posts.
     */
    protected static function isPlacedInModulesAndCards(): bool
    {
        return true;
    }

    /**
     * Social links can be placed in the top bar too.
     */
    protected static function isPlacedInTopBar(): bool
    {
        return true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'logo_style' => SocialLogoStyle::class,
            'logo_size' => 'integer',
            'opens_in_new_tab' => 'boolean',
            'links' => 'array',
        ];
    }

    public function shortcode(): string
    {
        return '[social:'.$this->slug.']';
    }

    /**
     * The links that can be shown, in order: each with its service, address
     * and, for LinkedIn, the library image holding its logo. A link without
     * an address, a LinkedIn link whose image is missing, and an address that
     * isn't a web or email link (such as javascript:) are skipped.
     *
     * @return Collection<int, array{service: SocialService, href: string, image: ?Image}>
     */
    public function socialLinks(): Collection
    {
        $links = collect($this->links ?? [])
            ->map(fn (mixed $link): array => is_array($link) ? $link : [])
            ->map(fn (array $link): array => [
                'service' => SocialService::tryFrom((string) ($link['service'] ?? '')),
                'url' => trim((string) ($link['url'] ?? '')),
                'image_id' => (int) ($link['image_id'] ?? 0),
            ])
            ->filter(fn (array $link): bool => $link['service'] !== null && $link['url'] !== '');

        $imageIds = $links->filter(fn (array $link): bool => $link['service']->usesOwnLogo())->pluck('image_id')->filter()->unique();
        $images = $imageIds->isEmpty() ? collect() : Image::query()->whereKey($imageIds)->get()->keyBy('id');

        return $links
            ->map(fn (array $link): array => [
                'service' => $link['service'],
                'href' => $link['service']->href($link['url']),
                'image' => $link['service']->usesOwnLogo() ? $images->get($link['image_id']) : null,
            ])
            ->reject(fn (array $link): bool => $link['service']->usesOwnLogo() && $link['image'] === null)
            ->filter(fn (array $link): bool => (bool) preg_match('#^(https?://|mailto:)#i', $link['href']))
            ->values();
    }

    /**
     * The names of the services linked to, in order, for the admin's list.
     *
     * @return Collection<int, string>
     */
    public function serviceNames(): Collection
    {
        return collect($this->links ?? [])
            ->map(fn (mixed $link): ?SocialService => is_array($link) ? SocialService::tryFrom((string) ($link['service'] ?? '')) : null)
            ->filter()
            ->map(fn (SocialService $service): string => $service->title())
            ->values();
    }

    /**
     * Whether a link uses the given library image (LinkedIn's logo).
     */
    public function usesImage(Image $image): bool
    {
        return collect($this->links ?? [])
            ->contains(fn (mixed $link): bool => is_array($link)
                && SocialService::tryFrom((string) ($link['service'] ?? ''))?->usesOwnLogo()
                && (int) ($link['image_id'] ?? 0) === $image->id);
    }

    /**
     * The logo size in pixels, kept within the allowed range.
     */
    public function logoSize(): int
    {
        return max(self::MINIMUM_LOGO_SIZE, min(self::MAXIMUM_LOGO_SIZE, $this->logo_size ?: self::DEFAULT_LOGO_SIZE));
    }

    /**
     * The color the whole row is drawn in as a CSS variable, for the Custom
     * style; the other styles take theirs from the stylesheet.
     */
    public function colorVariable(): ?string
    {
        if ($this->logo_style !== SocialLogoStyle::Custom || ! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->logo_color)) {
            return null;
        }

        return '--social-color: '.$this->logo_color;
    }

    /**
     * The row's HTML, or nothing if it has no links to show yet.
     *
     * @param  ?string  $alignment  left, center or right to give the row a line of its own, or null to keep it in the line with the text around it.
     */
    public function html(?string $alignment = null): ?string
    {
        $links = $this->socialLinks();

        if ($links->isEmpty()) {
            return null;
        }

        return trim(view('components.site.social-links', [
            'set' => $this,
            'links' => $links,
            'alignment' => in_array($alignment, ['left', 'center', 'right'], true) ? $alignment : null,
        ])->render());
    }
}
