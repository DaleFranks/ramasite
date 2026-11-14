<?php

namespace App\Models;

use Database\Factories\RedirectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sends visitors from an address with no page (an old one, often) to another.
 *
 * Addresses are kept without their leading slash and in lower case, and are
 * matched ignoring case, as older sites' addresses often were. One ending in
 * "*" covers everything under it; a "*" at the end of the target is replaced
 * with whatever followed, so "img/*" to "/uploads/images/*" sends
 * "/img/seo.jpg" to "/uploads/images/seo.jpg". A redirect only applies where
 * no published page exists, so it can never hide a live page.
 */
#[Fillable([
    'from_path',
    'to',
    'status_code',
    'automatic',
    'hits',
    'last_hit_at',
])]
class Redirect extends Model
{
    /** @use HasFactory<RedirectFactory> */
    use HasFactory;

    /**
     * How many redirects in a row are followed when checking for a loop.
     * Browsers give up long before this.
     */
    private const LONGEST_TRAIL = 20;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status_code' => 301,
        'automatic' => false,
        'hits' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (Redirect $redirect): void {
            $redirect->from_path = self::normalizePath($redirect->from_path);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'automatic' => 'boolean',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    /**
     * An address as redirects store it: decoded, lower case, no leading or
     * trailing slash, and no query string.
     */
    public static function normalizePath(?string $path): string
    {
        $path = trim((string) $path);

        // Only a full address goes through parse_url(): on a bare path it reads
        // a colon, as in "item:12", as the start of a port number.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        $path = Str::before(Str::before($path, '?'), '#');

        return Str::lower(trim(rawurldecode($path), '/'));
    }

    /**
     * The redirect for an address: an exact match, or else the wildcard
     * covering it with the longest (most specific) beginning.
     */
    public static function findFor(string $path, ?int $exceptId = null): ?self
    {
        $path = self::normalizePath($path);

        if ($path === '') {
            return null;
        }

        $redirects = fn () => self::query()->when($exceptId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptId));

        return $redirects()->where('from_path', $path)->first()
            ?? $redirects()
                ->where('from_path', 'like', '%*')
                ->get()
                ->filter(fn (Redirect $redirect): bool => str_starts_with($path, rtrim($redirect->from_path, '*')))
                ->sortByDesc(fn (Redirect $redirect): int => strlen($redirect->from_path))
                ->first();
    }

    public function isWildcard(): bool
    {
        return str_ends_with($this->from_path, '*');
    }

    /**
     * Where a visitor to the given address is sent, keeping their query string
     * unless the target has its own.
     */
    public function targetFor(string $path, string $queryString = ''): string
    {
        $target = $this->destinationFor($path);

        if (! preg_match('#^https?://#i', $target)) {
            $target = url('/'.ltrim($target, '/'));
        }

        if ($queryString !== '' && ! str_contains($target, '?')) {
            $target .= '?'.$queryString;
        }

        return $target;
    }

    /**
     * Where a visitor to the given address is sent, as the redirect has it: a
     * full web address, or an address on this site such as "/services".
     */
    public function destinationFor(string $path): string
    {
        if ($this->isWildcard() && str_ends_with($this->to, '*')) {
            return substr($this->to, 0, -1).self::restOfPath($path, rtrim($this->from_path, '*'));
        }

        return $this->to;
    }

    /**
     * Whether a redirect from one address to another would send visitors round
     * in a loop through the other redirects, such as "a" to "/b" when "b"
     * already goes to "/a". The trail is followed on this site only, and ends
     * at a published page, as visits there are never redirected.
     *
     * @param  int|null  $exceptId  The redirect being edited, whose saved version doesn't count.
     */
    public static function wouldLoop(string $fromPath, string $to, ?int $exceptId = null): bool
    {
        $from = self::normalizePath($fromPath);
        $next = $to;

        for ($step = 0; $step < self::LONGEST_TRAIL; $step++) {
            if (preg_match('#^https?://#i', $next)) {
                return false;
            }

            $path = self::normalizePath(rtrim($next, '*'));

            if ($path === $from || (str_ends_with($from, '*') && str_starts_with($path, rtrim($from, '*')))) {
                return true;
            }

            if ($path === '' || Entry::query()->published()->whereRaw('lower(path) = ?', [$path])->exists()) {
                return false;
            }

            $redirect = self::findFor($path, $exceptId);

            if ($redirect === null) {
                return false;
            }

            $next = $redirect->destinationFor($path);
        }

        return true;
    }

    /**
     * The part of a visitor's address after a wildcard's beginning, with the
     * case they typed. Characters an address can't hold, such as spaces, are
     * encoded again, so "Price%20List.PDF" arrives as it was sent.
     */
    private static function restOfPath(string $path, string $beginning): string
    {
        $decoded = trim(rawurldecode(Str::before(Str::before($path, '?'), '#')), '/');
        $rest = mb_substr($decoded, mb_strlen($beginning));

        return (string) preg_replace_callback(
            "#[^A-Za-z0-9\\-._~!$&'()*+,;=:@/]#u",
            fn (array $character): string => rawurlencode($character[0]),
            $rest,
        );
    }

    /**
     * Send the visitor on, counting the use so it's clear which old addresses
     * still get traffic.
     */
    public function follow(Request $request): RedirectResponse
    {
        // Counted by the database, so visits arriving together are all counted
        $this->incrementQuietly('hits', 1, ['last_hit_at' => now()]);

        return redirect()->to($this->targetFor($request->path(), (string) $request->getQueryString()), $this->status_code);
    }

    /**
     * When a page moves, send its old address to the new one, and point
     * redirects that led to the old address straight at the new one, so
     * visitors never go through a chain.
     */
    public static function forMovedPage(string $oldPath, string $newPath): void
    {
        $old = self::normalizePath($oldPath);
        $new = self::normalizePath($newPath);

        if ($old === '' || $old === $new) {
            return;
        }

        self::query()->where('from_path', $new)->delete();
        self::query()->whereIn('to', ['/'.$old, $old])->update(['to' => '/'.$new]);

        self::query()->updateOrCreate(['from_path' => $old], [
            'to' => '/'.$new,
            'status_code' => 301,
            'automatic' => true,
        ]);
    }
}
