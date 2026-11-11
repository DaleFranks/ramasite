<?php

namespace App\Models;

use Database\Factories\RedirectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
    public static function findFor(string $path): ?self
    {
        $path = self::normalizePath($path);

        if ($path === '') {
            return null;
        }

        return self::query()->where('from_path', $path)->first()
            ?? self::query()
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
        $target = $this->to;

        if ($this->isWildcard() && str_ends_with($target, '*')) {
            $rest = Str::after(self::normalizePath($path), rtrim($this->from_path, '*'));
            $target = substr($target, 0, -1).$rest;
        }

        if (! preg_match('#^https?://#i', $target)) {
            $target = url('/'.ltrim($target, '/'));
        }

        if ($queryString !== '' && ! str_contains($target, '?')) {
            $target .= '?'.$queryString;
        }

        return $target;
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
