<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a slide's text sits over its image.
 */
enum SlideCaptionPosition: string implements HasLabel
{
    case Bottom = 'bottom';
    case Top = 'top';
    case Left = 'left';
    case Right = 'right';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bottom => 'Banner at bottom',
            self::Top => 'Banner at top',
            self::Left => 'Box at left',
            self::Right => 'Box at right',
        };
    }

    /**
     * The caption's class for this position, or null for the standard bottom banner.
     */
    public function cssClass(): ?string
    {
        return match ($this) {
            self::Bottom => null,
            self::Top => 'caption-top',
            self::Left => 'caption-left',
            self::Right => 'caption-right',
        };
    }
}
