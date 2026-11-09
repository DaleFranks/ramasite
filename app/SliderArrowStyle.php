<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * How a slider's previous and next arrows look.
 */
enum SliderArrowStyle: string implements HasLabel
{
    case Square = 'square';
    case Circle = 'circle';
    case OutlineCircle = 'outline-circle';
    case BareChevron = 'bare-chevron';
    case LongArrow = 'long-arrow';
    case SolidTriangle = 'solid-triangle';

    public function getLabel(): string
    {
        return match ($this) {
            self::Square => 'Square',
            self::Circle => 'Circle',
            self::OutlineCircle => 'Outline circle',
            self::BareChevron => 'Bare chevron',
            self::LongArrow => 'Long arrow',
            self::SolidTriangle => 'Solid triangle',
        };
    }

    /**
     * The slider's class for this style, or null for the standard square.
     */
    public function cssClass(): ?string
    {
        return $this === self::Square ? null : 'arrows-'.$this->value;
    }

    /**
     * Whether the arrow sits on a filled backing, whose color can be chosen.
     */
    public function hasBacking(): bool
    {
        return in_array($this, [self::Square, self::Circle], true);
    }

    /**
     * The left-pointing shape, drawn on a 24 by 24 grid in the arrow's color.
     * The next arrow is the same shape flipped.
     */
    public function iconShape(): string
    {
        $outline = 'fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"';

        return match ($this) {
            self::LongArrow => '<path d="M22 12H3m7-7-7 7 7 7" '.$outline.'/>',
            self::SolidTriangle => '<path d="M18 3v18L5 12z" fill="currentColor"/>',
            default => '<path d="M16 4l-8 8 8 8" '.$outline.'/>',
        };
    }
}
