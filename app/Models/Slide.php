<?php

namespace App\Models;

use App\SlideCaptionPosition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image in a slider, with an optional caption.
 */
#[Fillable([
    'image_id',
    'caption',
    'caption_position',
    'caption_text_color',
    'caption_background_color',
    'sort',
])]
class Slide extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'caption_position' => 'bottom',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'caption_position' => SlideCaptionPosition::class,
        ];
    }

    /**
     * @return BelongsTo<Slider, $this>
     */
    public function slider(): BelongsTo
    {
        return $this->belongsTo(Slider::class);
    }

    /**
     * @return BelongsTo<Image, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    /**
     * The caption's own colors as CSS, or null to use the standard ones.
     */
    public function captionStyle(): ?string
    {
        $rules = array_filter([
            filled($this->caption_text_color) ? 'color: '.$this->caption_text_color : null,
            filled($this->caption_background_color) ? 'background: '.$this->caption_background_color : null,
        ]);

        return $rules === [] ? null : implode('; ', $rules);
    }
}
