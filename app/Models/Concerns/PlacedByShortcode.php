<?php

namespace App\Models\Concerns;

use App\Markdown;
use App\Models\Card;
use App\Models\Entry;
use App\Models\Module;
use App\Models\Theme;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * For things content places by their slug with a shortcode (modules, cards,
 * sliders, forms and social links): finding where they're placed, so one in use is never
 * deleted or renamed, which would leave the shortcode showing as written.
 */
trait PlacedByShortcode
{
    /**
     * The shortcodes that place it, such as ['card', 'cards'].
     *
     * @return list<string>
     */
    abstract protected static function placingShortcodes(): array;

    /**
     * Whether modules and cards can place it as well as pages and posts. Only
     * forms and social links can; modules, cards and sliders go in pages and posts.
     */
    protected static function isPlacedInModulesAndCards(): bool
    {
        return false;
    }

    /**
     * Whether the Theme screen's top bar can place it. Only social links can.
     */
    protected static function isPlacedInTopBar(): bool
    {
        return false;
    }

    /**
     * Refuse to delete one in use. When it isn't, this returns nothing rather
     * than true, which would end the event before the model's own checks run.
     */
    protected static function bootPlacedByShortcode(): void
    {
        static::deleting(fn (self $placed): ?bool => $placed->usages()->isEmpty() ? null : false);
    }

    /**
     * Where content places it, by name, e.g. "Page: About us". Anything after
     * a "|" in the shortcode, such as an alignment, isn't part of the slug.
     *
     * @return Collection<int, string>
     */
    public function usages(): Collection
    {
        $slug = strtolower((string) $this->getRawOriginal('slug'));
        $pattern = '/\[(?:'.implode('|', static::placingShortcodes()).'):([^\]]+)\]/i';
        $places = fn (?string $body): bool => preg_match_all($pattern, (string) $body, $matches) > 0
            && collect($matches[1])->contains(fn (string $value): bool => in_array($slug, Markdown::slugs(Str::before($value, '|')), true));
        $containing = fn (string $model) => $model::query()->where('body', 'like', '%'.$slug.'%');

        $usages = $containing(Entry::class)->get(['id', 'type', 'title', 'body'])
            ->filter(fn (Entry $entry): bool => $places($entry->body))
            ->map(fn (Entry $entry): string => $entry->type->getLabel().': '.$entry->title)
            ->toBase();

        if (static::isPlacedInModulesAndCards()) {
            $usages = $usages
                ->merge($containing(Module::class)->get(['id', 'name', 'body'])->filter(fn (Module $module): bool => $places($module->body))
                    ->map(fn (Module $module): string => 'Module: '.$module->name))
                ->merge($containing(Card::class)->get(['id', 'name', 'body'])->filter(fn (Card $card): bool => $places($card->body))
                    ->map(fn (Card $card): string => 'Card: '.$card->name));
        }

        if (static::isPlacedInTopBar() && $places(Theme::current()->top_bar_text)) {
            $usages->push('Theme: top bar');
        }

        return $usages->values();
    }

    /**
     * A sentence for the admin saying where it's in use.
     */
    public function inUseMessage(): string
    {
        return 'It\'s placed with ['.static::placingShortcodes()[0].':'.$this->getRawOriginal('slug').'] in: '.$this->usages()->implode(', ').'.';
    }

    /**
     * A rule for the slug box: the slug can't change while content places it
     * with the old one, or that content would show the shortcode as written.
     */
    public static function slugUnchangedWhileInUse(?self $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record === null || (string) $value === $record->getRawOriginal('slug') || $record->usages()->isEmpty()) {
                return;
            }

            $fail($record->inUseMessage().' Change those to the new slug first.');
        };
    }
}
