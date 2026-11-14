<?php

namespace App\Models;

use App\BoxStyle;
use App\Markdown;
use App\Models\Concerns\PlacedByShortcode;
use App\TextAlignment;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A short block of Markdown shown as a card, placed in a page with
 * [card:slug] or side by side in a grid with [cards: slug-1, slug-2].
 */
#[Fillable([
    'name',
    'slug',
    'body',
    'style',
    'featured',
    'text_alignment',
])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory;

    use PlacedByShortcode;

    /**
     * @return list<string>
     */
    protected static function placingShortcodes(): array
    {
        return ['card', 'cards'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'style' => BoxStyle::class,
            'featured' => 'boolean',
            'text_alignment' => TextAlignment::class,
        ];
    }

    /**
     * The body as HTML in a container whose classes give the card's look,
     * e.g. "card featured-card theme-dark".
     */
    public function html(): string
    {
        $classes = collect([
            'card',
            $this->featured ? 'featured-card' : null,
            $this->style ? 'theme-'.$this->style->value : null,
            $this->text_alignment ? 'card-align-'.$this->text_alignment->value : null,
        ])->filter()->implode(' ');

        $body = Markdown::toHtml((string) $this->body);

        if ($this->style?->showsLinksAsButtons()) {
            $body = Markdown::withButtonLinks($body);
        }

        return '<div class="'.e($classes).'">'."\n".$body."\n".'</div>';
    }
}
