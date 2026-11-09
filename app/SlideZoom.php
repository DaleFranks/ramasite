<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * Whether a slide's image slowly zooms while it's shown.
 */
enum SlideZoom: string implements HasLabel
{
    case Off = 'off';
    case In = 'in';
    case Out = 'out';

    public function getLabel(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::In => 'Zoom in',
            self::Out => 'Zoom out',
        };
    }

    /**
     * The slide's class for this choice, or null when the image stays still.
     */
    public function cssClass(): ?string
    {
        return match ($this) {
            self::Off => null,
            self::In => 'zoom-in',
            self::Out => 'zoom-out',
        };
    }
}
