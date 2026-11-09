<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * How many columns of post cards a blog's page shows.
 */
enum BlogColumns: string implements HasLabel
{
    case Automatic = 'automatic';
    case One = 'one';
    case Two = 'two';
    case Three = 'three';

    public function getLabel(): string
    {
        return match ($this) {
            self::Automatic => 'Automatic (fits the window)',
            self::One => '1 column',
            self::Two => '2 columns',
            self::Three => '3 columns',
        };
    }

    /**
     * The card grid class for this choice, or null when the grid's own
     * formula decides from the window's width.
     */
    public function cssClass(): ?string
    {
        return match ($this) {
            self::Automatic => null,
            self::One => 'columns-1',
            self::Two => 'columns-2',
            self::Three => 'columns-3',
        };
    }
}
