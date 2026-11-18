<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * How a set of social links colors its logos.
 */
enum SocialLogoStyle: string implements HasLabel
{
    case Color = 'color';
    case Dark = 'dark';
    case Light = 'light';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::Color => 'Brand colors',
            self::Dark => 'Dark',
            self::Light => 'Light (white)',
            self::Custom => 'Custom color',
        };
    }
}
