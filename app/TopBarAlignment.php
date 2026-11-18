<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * Where the top bar's line sits across the bar. Spread puts the text at the
 * left and any social links at the right.
 */
enum TopBarAlignment: string implements HasLabel
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';
    case Spread = 'spread';

    public function getLabel(): string
    {
        return match ($this) {
            self::Left => 'Left',
            self::Center => 'Centered',
            self::Right => 'Right',
            self::Spread => 'Spread: text left, social links right',
        };
    }
}
