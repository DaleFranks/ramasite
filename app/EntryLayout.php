<?php

namespace App;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How an entry's content is laid out between the header and footer.
 */
enum EntryLayout: string implements HasDescription, HasLabel
{
    case Standard = 'standard';
    case FullWidth = 'full-width';
    case Landing = 'landing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::FullWidth => 'Full width',
            self::Landing => 'Landing',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Standard => 'The title, then the content in a column as wide as set on the Theme screen.',
            self::FullWidth => 'The title, then the content as wide as the header.',
            self::Landing => 'Modules stretch across the whole window as bands. The title is hidden unless it\'s switched on below, so start with a slider or a module that has its own heading.',
        };
    }

    public function showsTitle(): bool
    {
        return $this !== self::Landing;
    }
}
