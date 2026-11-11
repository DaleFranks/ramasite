<?php

namespace App;

use App\Models\Image;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * A copy of everything that makes a site its own, as one zip: the database
 * (all pages, settings, accounts and form submissions), the uploaded images,
 * logo and tab icon, and the .env settings file, whose key unlocks the saved
 * SMTP password and two-step login.
 *
 * Inside the zip the files sit where they belong in the site's folder, so
 * restoring is uploading them over a copy of the CMS.
 */
class Backup
{
    /**
     * What the zips waiting to be downloaded are called in the backups folder.
     * The random part means one can't be guessed or overwritten by another.
     */
    public const ZIP_NAME_PATTERN = 'backup-[A-Za-z0-9]{32}\.zip';

    /**
     * How long a zip that was never downloaded is kept.
     */
    private const ABANDONED_AFTER_SECONDS = 3600;

    /**
     * How long making a backup may take, in seconds: longer than the few seconds
     * hosts usually give a page, for a site with a large library.
     */
    private const TIME_LIMIT_SECONDS = 300;

    /**
     * Uploads whose contents are already compressed, so squeezing them again
     * would take time and save next to nothing. They go in the zip as they are.
     *
     * @var list<string>
     */
    private const ALREADY_COMPRESSED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'pdf', 'zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'woff', 'woff2'];

    /**
     * Whether this server can make zip files.
     */
    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * The folder the zips are made in.
     */
    public static function folder(): string
    {
        return storage_path('app/backups');
    }

    /**
     * Make the zip and return where it is. The caller removes it once sent.
     */
    public static function create(): string
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('This server can\'t make zip files (PHP\'s zip extension is missing).');
        }

        // Where the host allows it; otherwise the host's own limit stays
        if (function_exists('set_time_limit')) {
            set_time_limit(self::TIME_LIMIT_SECONDS);
        }

        $folder = self::folder();
        File::ensureDirectoryExists($folder);
        self::deleteAbandonedZips();

        $zipPath = $folder.'/backup-'.Str::random(32).'.zip';
        $snapshotPath = $folder.'/'.Str::random(16).'.sqlite';

        try {
            // A snapshot rather than the live file, so a change made while the backup
            // is being made can't leave a half-written copy.
            DatabaseCopy::to($snapshotPath);

            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The backup file couldn\'t be created.');
            }

            $zip->addFile($snapshotPath, 'database/database.sqlite');

            if (File::exists(app()->environmentFilePath())) {
                $zip->addFile(app()->environmentFilePath(), '.env');
            }

            // Which database changes the copy has, so restoring it onto a newer CMS updates it
            $zip->addFromString('storage/app/database-version', (string) DatabaseUpdate::appliedVersion()."\n");

            $uploads = Storage::disk(Image::DISK);

            foreach ($uploads->allFiles() as $file) {
                $zip->addFile($uploads->path($file), 'public/uploads/'.$file);

                if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::ALREADY_COMPRESSED, true)) {
                    $zip->setCompressionName('public/uploads/'.$file, ZipArchive::CM_STORE);
                }
            }

            $zip->addFromString('RESTORING.txt', str_replace('%VERSION%', Version::label(), self::instructions()));

            if (! $zip->close()) {
                throw new RuntimeException('The backup file couldn\'t be saved. The server may be out of disk space.');
            }
        } finally {
            File::delete($snapshotPath);
        }

        return $zipPath;
    }

    /**
     * Such as "backup-example.com-2026-11-01-1530.zip".
     */
    public static function filename(): string
    {
        $site = Str::slug(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'site', '.');

        return 'backup-'.$site.'-'.now()->format('Y-m-d-Hi').'.zip';
    }

    /**
     * A zip stays behind if its download never finishes. Each one holds the admin
     * accounts and takes up as much room as the uploads, so none should linger.
     */
    private static function deleteAbandonedZips(): void
    {
        foreach (File::glob(self::folder().'/backup-*.zip') as $zipPath) {
            if (File::lastModified($zipPath) < time() - self::ABANDONED_AFTER_SECONDS) {
                File::delete($zipPath);
            }
        }
    }

    private static function instructions(): string
    {
        return <<<'TEXT'
            RamaSite backup
            ================

            Made with %VERSION%.

            This zip holds everything that makes the site its own:

              database/database.sqlite   pages, posts, cards, modules, sliders, forms,
                                         form submissions, redirects, the Theme and
                                         Email settings, and the admin accounts
              public/uploads/            the image and file libraries, logo and tab icon
              .env                       the site's settings file; its key unlocks the
                                         saved SMTP password and two-step login
              storage/app/database-version   which database changes the copy has

            To restore the site:

              1. Put a copy of RamaSite on the server: the version the site was
                 running when this backup was made, or a newer one. A newer one
                 updates the database itself on the first visit.
              2. Upload the files in this zip into the site's folder, replacing the
                 ones already there.
              3. If the site's address has changed, open .env and change APP_URL to
                 the new address.

            Keep this zip private: it contains the admin accounts and the key to the
            saved passwords.

            TEXT;
    }
}
