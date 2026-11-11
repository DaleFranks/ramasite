<?php

namespace App\Models;

use App\BoxStyle;
use App\FooterStyle;
use App\LogoPosition;
use App\SiteNamePosition;
use App\TextAlignment;
use App\ThemeFonts;
use App\ThemeOptions;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\ThemeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The site's look: branding, colors, fonts and layout options, edited on the
 * Theme screen and output as CSS variables that override the stylesheet defaults.
 */
#[Fillable([
    'site_name',
    'timezone',
    'site_name_position',
    'logo_path',
    'favicon_path',
    'logo_position',
    'sticky_header',
    'top_bar_text',
    'top_bar_style',
    'top_bar_alignment',
    'top_bar_stays_visible',
    'color_primary',
    'color_secondary',
    'color_background',
    'color_text',
    'color_card_background',
    'color_header_background',
    'font_body',
    'font_headings',
    'border_radius',
    'content_width',
    'footer_style',
    'custom_css',
    'options',
])]
class Theme extends Model
{
    /** @use HasFactory<ThemeFactory> */
    use HasFactory;

    /**
     * The original look.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'site_name_position' => 'beside',
        'logo_path' => null,
        'favicon_path' => null,
        'logo_position' => 'left',
        'sticky_header' => true,
        'top_bar_style' => 'dark',
        'top_bar_alignment' => 'center',
        'top_bar_stays_visible' => true,
        'color_primary' => '#2563eb',
        'color_secondary' => '#0f172a',
        'color_background' => '#f8fafc',
        'color_text' => '#1e293b',
        'color_card_background' => '#ffffff',
        'color_header_background' => '#ffffff',
        'font_body' => 'Noto Sans',
        'font_headings' => 'Noto Serif',
        'border_radius' => 12,
        'content_width' => 840,
        'footer_style' => 'dark',
        'custom_css' => null,
        'options' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'site_name_position' => SiteNamePosition::class,
            'sticky_header' => 'boolean',
            'top_bar_style' => BoxStyle::class,
            'top_bar_alignment' => TextAlignment::class,
            'top_bar_stays_visible' => 'boolean',
            'logo_position' => LogoPosition::class,
            'footer_style' => FooterStyle::class,
            'options' => 'array',
            'border_radius' => 'integer',
            'content_width' => 'integer',
        ];
    }

    /**
     * The site's theme, created with the default look the first time it's needed.
     */
    public static function current(): self
    {
        return self::query()->oldest('id')->firstOr(
            // Reloaded so it has the columns' own defaults, such as the timezone
            fn (): self => self::query()->create([
                ...self::DEFAULTS,
                'site_name' => config('app.name'),
            ])->refresh(),
        );
    }

    /**
     * Put every setting except the site name back to the original look.
     */
    /**
     * The timezone dates are shown and entered in, chosen on the Theme screen.
     * Times are stored in UTC whatever it is, so changing it never moves the
     * moment a post appears.
     */
    public static function siteTimezone(): string
    {
        return once(fn (): string => self::current()->timezone ?: 'UTC');
    }

    /**
     * A stored time, as the site's visitors and admins should see it.
     */
    public static function localTime(CarbonInterface $time): CarbonInterface
    {
        return $time->copy()->setTimezone(self::siteTimezone());
    }

    public function resetToDefaults(): void
    {
        $this->update(self::DEFAULTS);
    }

    /**
     * The value of one of the detailed settings in ThemeOptions: the one chosen
     * on the Theme screen, or the automatic one if it's empty.
     */
    public function option(string $key): mixed
    {
        $value = $this->options[$key] ?? null;

        return filled($value) ? $value : $this->automaticOption($key);
    }

    /**
     * What a detailed setting is when left empty, worked out from the main settings.
     */
    public function automaticOption(string $key): mixed
    {
        $default = ThemeOptions::all()[$key]['default'] ?? null;

        return $default instanceof Closure ? $default($this) : $default;
    }

    /**
     * The CSS variables that override the stylesheet's defaults: the main
     * settings, then every detailed setting (chosen or automatic).
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        $variables = [
            '--color-primary' => $this->color_primary,
            '--color-primary-rgb' => implode(', ', self::rgb($this->color_primary)),
            '--color-secondary' => $this->color_secondary,
            '--color-bg-body' => $this->color_background,
            '--color-text-main' => $this->color_text,
            '--color-bg-card' => $this->color_card_background,
            '--header-bg-color' => self::rgba($this->color_header_background, 0.85),
            '--font-family-body' => self::fontStack($this->font_body),
            '--font-family-headers' => self::fontStack($this->font_headings),
            '--border-radius' => $this->border_radius.'px',
            '--border-radius-sm' => (int) round($this->border_radius / 2).'px',
            '--max-width-content' => $this->content_width.'px',
        ];

        foreach (ThemeOptions::all() as $key => $option) {
            $value = $this->option($key);

            $variables = [...$variables, ...(isset($option['css'])
                ? $option['css']($value, $this)
                : [$option['var'] => $this->optionCss($option, $value)])];
        }

        return $variables;
    }

    /**
     * A detailed setting's value as CSS.
     *
     * @param  array<string, mixed>  $option
     */
    private function optionCss(array $option, mixed $value): string
    {
        return match ($option['type']) {
            'color' => isset($option['alpha']) ? self::rgba((string) $value, $option['alpha']) : (string) $value,
            'px' => self::pxCss((float) $value),
            'font' => self::fontStack((string) $value),
            'code_font' => "'{$value}', monospace",
            'image' => filled($value) ? "url('".Storage::disk('uploads')->url((string) $value)."')" : 'none',
            'toggle' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    /**
     * A size as CSS, such as "17.6px".
     */
    public static function pxCss(float $pixels): string
    {
        return rtrim(rtrim(number_format($pixels, 2, '.', ''), '0'), '.').'px';
    }

    /**
     * Classes for the <body> element that switch on the header options.
     */
    public function bodyClasses(): string
    {
        return collect([
            'logo-'.$this->logo_position->value,
            $this->site_name_position === SiteNamePosition::Below ? 'site-name-below' : null,
            $this->sticky_header ? null : 'header-not-sticky',
            $this->hasTopBar() ? 'has-top-bar' : null,
            $this->hasTopBar() && ! $this->top_bar_stays_visible ? 'top-bar-scrolls-away' : null,
        ])->filter()->implode(' ');
    }

    /**
     * Whether a line of text (such as a phone number) is shown in a bar above
     * the logo and menu.
     */
    public function hasTopBar(): bool
    {
        return filled($this->top_bar_text);
    }

    /**
     * The top bar's text as HTML. It may use Markdown for bold text and links,
     * such as [(760) 555-0100](tel:+17605550100) to call from a phone. Like page
     * content, it can't hold links that run code (javascript: and the like).
     */
    public function topBarHtml(): HtmlString
    {
        return new HtmlString(Str::inlineMarkdown((string) $this->top_bar_text, ['allow_unsafe_links' => false]));
    }

    /**
     * The custom CSS, made safe to place inside a <style> element.
     */
    public function safeCustomCss(): string
    {
        return str_replace('<', '', (string) $this->custom_css);
    }

    /**
     * The Google Fonts stylesheet URL for every font the site uses.
     */
    public function googleFontsUrl(): string
    {
        return ThemeFonts::googleFontsUrl(
            collect([$this->font_body, $this->font_headings, ...array_map($this->option(...), ['font_quotes', 'font_display', 'font_code', 'heading_font_h2', 'heading_font_h3', 'heading_font_h4', 'heading_font_h5', 'heading_font_h6'])])
                ->when($this->hasTopBar(), fn ($fonts) => $fonts->push($this->option('top_bar_font'))),
        );
    }

    /**
     * Whether the header shows the site name: unless it's set to logo only.
     * Without a logo, the name always shows, so the header is never empty.
     */
    public function showsSiteName(): bool
    {
        return $this->site_name_position !== SiteNamePosition::Hidden || ! $this->logoUrl();
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('uploads')->url($this->logo_path) : null;
    }

    public function faviconUrl(): ?string
    {
        return $this->favicon_path ? Storage::disk('uploads')->url($this->favicon_path) : null;
    }

    private static function fontStack(string $font): string
    {
        return ThemeFonts::stack($font);
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function rgba(string $hex, float $alpha): string
    {
        return 'rgba('.implode(', ', self::rgb($hex)).', '.$alpha.')';
    }

    public static function darken(string $hex, float $amount): string
    {
        return self::mix($hex, 0, $amount);
    }

    public static function lighten(string $hex, float $amount): string
    {
        return self::mix($hex, 255, $amount);
    }

    /**
     * Move each color channel the given fraction of the way towards the target.
     */
    private static function mix(string $hex, int $target, float $amount): string
    {
        return '#'.collect(self::rgb($hex))
            ->map(fn (int $channel): string => str_pad(dechex((int) round($channel + ($target - $channel) * $amount)), 2, '0', STR_PAD_LEFT))
            ->implode('');
    }
}
