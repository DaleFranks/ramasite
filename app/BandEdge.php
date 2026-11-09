<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * The shape of a module band's top or bottom edge on a Landing page.
 * A slant left rises toward the left of the window; a slant right, the right.
 */
enum BandEdge: string implements HasLabel
{
    case None = 'none';
    case SlantLeft = 'slant-left';
    case SlantRight = 'slant-right';
    case Wave = 'wave';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'None',
            self::SlantLeft => 'Slant left',
            self::SlantRight => 'Slant right',
            self::Wave => 'Wave',
        };
    }
}
