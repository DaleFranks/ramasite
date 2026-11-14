<?php

namespace App;

use App\Http\Middleware\RedirectToHttps;
use Closure;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The site's own address, saved as APP_URL in the .env settings file.
 */
class SiteAddress
{
    /**
     * Save the address the owner finished setup on. It replaces the one taken
     * from the site's first visit, which may have been a bot's. The login
     * cookies made during this same visit follow the new address too.
     *
     * A site whose settings file can't be changed still works, on the address
     * it already has, so that doesn't stop setup finishing.
     */
    public static function save(string $address): void
    {
        config(['app.url' => $address]);

        if (RedirectToHttps::isRequired()) {
            config(['session.secure' => true]);
            Cookie::setDefaultPathAndDomain(config('session.path'), config('session.domain'), true, config('session.same_site'));
        }

        try {
            self::writeToSettingsFile($address);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The site's own domain, such as "example.com", taken from its saved
     * address rather than from the visit, which can claim any domain.
     */
    public static function host(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
    }

    /**
     * The start of the address search engines are told is a page's one true
     * address, such as "https://example.com", or "https://example.com/site"
     * for a site in a folder.
     *
     * The scheme and domain are the saved address's when the visit is to the
     * same domain, with or without "www.", so both forms point to one. A
     * visit to another domain keeps its own, so a site that moved to a new
     * domain without its saved address being updated still points to itself.
     */
    public static function canonicalRoot(): string
    {
        return self::canonicalOrigin().rtrim(request()->getBaseUrl(), '/');
    }

    /**
     * canonicalRoot() without the site's folder, such as "https://example.com",
     * for addresses that already include the folder, such as an uploaded
     * image's "/site/uploads/images/team.jpg".
     */
    public static function canonicalOrigin(): string
    {
        $request = request();
        $saved = (string) config('app.url');
        $savedScheme = strtolower((string) parse_url($saved, PHP_URL_SCHEME));
        $savedHost = strtolower((string) parse_url($saved, PHP_URL_HOST));

        if (! in_array($savedScheme, ['http', 'https'], true) || self::withoutWww($savedHost) !== self::withoutWww($request->getHost())) {
            return $request->getSchemeAndHttpHost();
        }

        $port = parse_url($saved, PHP_URL_PORT);

        return $savedScheme.'://'.$savedHost.($port ? ':'.$port : '');
    }

    /**
     * Run some code with every address it makes starting with the site's saved
     * address, for links that are emailed. A visit can claim to be for any
     * domain, and a link built from it, such as a password reset link, would
     * then lead to someone else's site, which could take what the link carries.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function withLinksToSite(Closure $callback): mixed
    {
        $address = (string) config('app.url');

        if (! preg_match('#^https?://#i', $address)) {
            return $callback();
        }

        URL::forceRootUrl($address);
        URL::forceScheme(strtolower((string) parse_url($address, PHP_URL_SCHEME)));

        try {
            return $callback();
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }

    private static function withoutWww(string $host): string
    {
        return (string) preg_replace('/^www\./i', '', strtolower($host));
    }

    private static function writeToSettingsFile(string $address): void
    {
        $file = app()->environmentFilePath();
        $settings = File::get($file);
        $line = 'APP_URL='.$address;
        $pattern = '/^APP_URL=.*$/m';

        // The file keeps the permissions it has, which a host may have tightened
        $permissions = @fileperms($file);

        File::replace($file, preg_match($pattern, $settings)
            ? preg_replace_callback($pattern, fn (): string => $line, $settings)
            : rtrim($settings)."\n".$line."\n", $permissions === false ? 0644 : $permissions & 0777);
    }
}
