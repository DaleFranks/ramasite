<?php

namespace App\Models;

use App\SliderAnimation;
use App\SliderArrowStyle;
use Database\Factories\SliderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A slideshow of library images, placed in a page with [slider:slug].
 */
#[Fillable([
    'name',
    'slug',
    'animation',
    'seconds_per_slide',
    'edge_to_edge',
    'arrow_style',
    'arrow_color',
    'arrow_background_color',
])]
class Slider extends Model
{
    /**
     * Narrower images than this are pointed out on an edge-to-edge slider, as
     * they'll be stretched across a wide window.
     */
    public const EDGE_TO_EDGE_IMAGE_WIDTH = 1920;

    /**
     * How far apart two images' proportions may be and still count as the same shape.
     */
    private const SAME_SHAPE_TOLERANCE = 0.01;

    /**
     * How solid an arrow's chosen backing color is shown, normally and when pointed at.
     */
    private const ARROW_BACKING_OPACITY = 0.5;

    private const ARROW_BACKING_HOVER_OPACITY = 0.8;

    /** @use HasFactory<SliderFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'animation' => 'slide',
        'arrow_style' => 'square',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'animation' => SliderAnimation::class,
            'seconds_per_slide' => 'integer',
            'edge_to_edge' => 'boolean',
            'arrow_style' => SliderArrowStyle::class,
        ];
    }

    /**
     * The slides, in the order they're shown.
     *
     * @return HasMany<Slide, $this>
     */
    public function slides(): HasMany
    {
        return $this->hasMany(Slide::class)->orderBy('sort');
    }

    public function shortcode(): string
    {
        return '[slider:'.$this->slug.']';
    }

    /**
     * This slider's own arrow colors as CSS variables, or null to use the Theme's.
     * The dots use the arrow color too.
     */
    public function arrowColorVariables(): ?string
    {
        $hasOwnBacking = $this->arrow_style?->hasBacking() && filled($this->arrow_background_color);

        $variables = array_filter([
            filled($this->arrow_color) ? '--slider-nav-color: '.$this->arrow_color : null,
            $hasOwnBacking ? '--slider-nav-bg: '.self::rgba($this->arrow_background_color, self::ARROW_BACKING_OPACITY) : null,
            $hasOwnBacking ? '--slider-nav-bg-hover: '.self::rgba($this->arrow_background_color, self::ARROW_BACKING_HOVER_OPACITY) : null,
        ]);

        return $variables === [] ? null : implode('; ', $variables);
    }

    /**
     * A color like "#112233" at the given opacity, as CSS.
     */
    private static function rgba(string $hexColor, float $opacity): string
    {
        [$red, $green, $blue] = sscanf($hexColor, '#%02x%02x%02x');

        return "rgba({$red}, {$green}, {$blue}, {$opacity})";
    }

    /**
     * What may look wrong with this slider's images when it runs edge to edge.
     * It's only ever pointed out; the slider saves whatever its images are.
     *
     * @return list<string>
     */
    public function edgeToEdgeImageWarnings(): array
    {
        if (! $this->edge_to_edge) {
            return [];
        }

        $images = $this->slides()->with('image')->get()
            ->pluck('image')
            ->unique('id')
            ->filter(fn (Image $image): bool => $image->width > 0 && $image->height > 0);

        $narrowImages = $images->filter(fn (Image $image): bool => $image->width < self::EDGE_TO_EDGE_IMAGE_WIDTH);
        $shapes = $images->map(fn (Image $image): float => $image->width / $image->height);

        $warnings = [];

        if ($narrowImages->isNotEmpty()) {
            $warnings[] = sprintf(
                'These images are under %d pixels wide, so they may look blurry across a wide window: %s.',
                self::EDGE_TO_EDGE_IMAGE_WIDTH,
                $narrowImages->map(fn (Image $image): string => "{$image->filename} ({$image->width} wide)")->implode(', '),
            );
        }

        if ($images->isNotEmpty() && $shapes->min() < $shapes->max() * (1 - self::SAME_SHAPE_TOLERANCE)) {
            $warnings[] = 'The slides aren\'t all the same shape, so the slider takes the height of the tallest and shorter ones leave a gap beneath them.';
        }

        return $warnings;
    }

    /**
     * The slider's HTML, or nothing if it has no slides yet.
     */
    public function html(): ?string
    {
        $slides = $this->slides()->with('image')->get();

        if ($slides->isEmpty()) {
            return null;
        }

        return view('components.site.slider', ['slider' => $this, 'slides' => $slides])->render();
    }
}
