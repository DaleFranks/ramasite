<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * How a module or card is colored: as a dark, light or primary-colored panel.
 */
enum BoxStyle: string implements HasLabel
{
    case Dark = 'dark';
    case Light = 'light';
    case Primary = 'primary';

    /**
     * Whether a link on a line of its own shows as a button, as on the Dark
     * and Primary panels. Links within text always stay ordinary links.
     */
    public function showsLinksAsButtons(): bool
    {
        return $this !== self::Light;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Dark => 'Dark',
            self::Light => 'Light',
            self::Primary => 'Primary color',
        };
    }
}
