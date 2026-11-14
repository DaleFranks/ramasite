<?php

namespace App;

use Illuminate\Support\Facades\File;

/**
 * Which version of RamaSite this copy is, such as "v0.4.0-alpha".
 *
 * The release build writes it into a VERSION file in the site's folder. A copy
 * that didn't come from a release (one being worked on) has no such file.
 */
class Version
{
    public const DEVELOPMENT = 'development';

    public static function current(): string
    {
        $path = base_path('VERSION');

        $version = File::exists($path) ? trim(File::get($path)) : '';

        return $version !== '' ? $version : self::DEVELOPMENT;
    }

    /**
     * Where compiled page templates are kept: a folder for this version inside
     * $root, so a new version never uses templates compiled for an older one.
     * (Laravel only recompiles a template when its file is newer than the
     * compiled copy, and unzipping a release keeps the files' original dates.)
     * Until the first template compiled for this version creates its folder,
     * older versions' templates are deleted. The folder isn't made here, so a
     * release build (which runs the app while installing packages) ships none.
     */
    public static function compiledViewsPath(string $root): string
    {
        $path = $root.'/'.preg_replace('/[^A-Za-z0-9.-]+/', '-', self::current());

        if (! is_dir($path) && is_dir($root)) {
            // Not this version's own, which a visit arriving at the same moment may just have made
            foreach (File::directories($root) as $olderVersion) {
                if ($olderVersion !== $path) {
                    File::deleteDirectory($olderVersion);
                }
            }

            // Compiled before versions had folders of their own
            File::delete(File::glob($root.'/*.php'));
        }

        return $path;
    }

    /**
     * Such as "RamaSite v0.4.0-alpha".
     */
    public static function label(): string
    {
        return 'RamaSite '.self::current();
    }
}
