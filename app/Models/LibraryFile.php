<?php

namespace App\Models;

use App\Models\Concerns\UsedInContent;
use Database\Factories\LibraryFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A file in the file library, such as a PDF menu or a price list, stored in
 * public/uploads/files and linked from content with [file:menu.pdf].
 *
 * Like images, uploads keep a tidied version of their own name and are numbered
 * when it's taken, and replacing one keeps its name so every link keeps working.
 *
 * Only the documents in TYPES may be uploaded. Anything a browser would open as
 * a page of the site (HTML, SVG, XML) or a server would run (scripts) is
 * refused, since the files are served straight from the site's own address.
 * Audio and video are left to services built for them (such as YouTube), as
 * they're too large for most hosts' upload limits and would swell backups.
 */
#[Fillable([
    'filename',
    'size',
    'mime_type',
    'replaced_at',
])]
class LibraryFile extends Model
{
    /** @use HasFactory<LibraryFileFactory> */
    use HasFactory;

    use UsedInContent;

    public const DISK = Image::DISK;

    public const DIRECTORY = 'files';

    /**
     * The types that may be uploaded, by extension: the name visitors see beside a
     * link, and the types the file's contents may be detected as. Office files
     * are zips inside, so older servers may only detect them as that.
     *
     * @var array<string, array{label: string, mime_types: list<string>}>
     */
    public const TYPES = [
        'pdf' => ['label' => 'PDF', 'mime_types' => ['application/pdf']],
        'doc' => ['label' => 'Word', 'mime_types' => ['application/msword', ...self::OLD_OFFICE_MIME_TYPES]],
        'docx' => ['label' => 'Word', 'mime_types' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::ZIPPED_MIME_TYPES]],
        'xls' => ['label' => 'Excel', 'mime_types' => ['application/vnd.ms-excel', ...self::OLD_OFFICE_MIME_TYPES]],
        'xlsx' => ['label' => 'Excel', 'mime_types' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIPPED_MIME_TYPES]],
        'ppt' => ['label' => 'PowerPoint', 'mime_types' => ['application/vnd.ms-powerpoint', ...self::OLD_OFFICE_MIME_TYPES]],
        'pptx' => ['label' => 'PowerPoint', 'mime_types' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIPPED_MIME_TYPES]],
        'odt' => ['label' => 'OpenDocument text', 'mime_types' => ['application/vnd.oasis.opendocument.text', ...self::ZIPPED_MIME_TYPES]],
        'ods' => ['label' => 'OpenDocument spreadsheet', 'mime_types' => ['application/vnd.oasis.opendocument.spreadsheet', ...self::ZIPPED_MIME_TYPES]],
        'odp' => ['label' => 'OpenDocument presentation', 'mime_types' => ['application/vnd.oasis.opendocument.presentation', ...self::ZIPPED_MIME_TYPES]],
        'txt' => ['label' => 'Text', 'mime_types' => ['text/plain']],
        'csv' => ['label' => 'CSV', 'mime_types' => ['text/csv', 'text/plain', 'application/csv']],
        'zip' => ['label' => 'ZIP', 'mime_types' => ['application/zip', 'application/x-zip-compressed']],
    ];

    private const ZIPPED_MIME_TYPES = ['application/zip', 'application/octet-stream'];

    private const OLD_OFFICE_MIME_TYPES = ['application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'];

    protected static function booted(): void
    {
        static::deleting(function (LibraryFile $file): bool {
            return $file->usages()->isEmpty();
        });

        static::deleted(function (LibraryFile $file): void {
            Storage::disk(self::DISK)->delete($file->path());
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
            'size' => 'integer',
            'replaced_at' => 'datetime',
        ];
    }

    /**
     * Why an upload can't go in the library, or nothing if it can.
     */
    public static function problemWith(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $type = self::TYPES[$extension] ?? null;

        if ($type === null) {
            return 'Files of this type can\'t be uploaded. The library takes '.self::typeList().'.';
        }

        if (! in_array((string) $file->getMimeType(), $type['mime_types'], true)) {
            return 'This file doesn\'t look like a '.$type['label'].' file inside, so it can\'t be uploaded.';
        }

        return null;
    }

    /**
     * The types that may be uploaded, for messages, e.g. "PDF, Word, … and ZIP files".
     */
    public static function typeList(): string
    {
        return collect(self::TYPES)->pluck('label')->unique()->join(', ', ' and ').' files';
    }

    /**
     * Add an uploaded file to the library, exactly as it is, under a name no other file has.
     *
     * @throws RuntimeException With a message for the person uploading it.
     */
    public static function storeUpload(UploadedFile $file): self
    {
        $problem = self::problemWith($file);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $filename = self::uniqueFilename(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), strtolower($file->getClientOriginalExtension()));
        $path = $file->storeAs(self::DIRECTORY, $filename, self::DISK);

        return self::query()->create([
            'filename' => $filename,
            'size' => (int) filesize(Storage::disk(self::DISK)->path($path)),
            'mime_type' => (string) $file->getMimeType(),
        ]);
    }

    /**
     * Swap in a new file under this file's name, so every link to it gets the new
     * one. It's put in place only once it's safely on the disk, so a failure
     * leaves the old one untouched.
     *
     * @throws RuntimeException With a message for the person replacing it.
     */
    public function replaceWith(UploadedFile $file): void
    {
        $problem = self::problemWith($file);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        if (strtolower($file->getClientOriginalExtension()) !== $this->extension()) {
            throw new RuntimeException(sprintf(
                'This is a %s file, so the new file must be too. To use another type, upload it as a new file.',
                strtoupper($this->extension()),
            ));
        }

        $disk = Storage::disk(self::DISK);
        $temporaryPath = $file->storeAs(self::DIRECTORY, '.replacing-'.Str::random(32), self::DISK);

        try {
            if ($temporaryPath === false || ! rename($disk->path($temporaryPath), $disk->path($this->path()))) {
                throw new RuntimeException('The new file couldn\'t be saved, so the file hasn\'t changed.');
            }
        } finally {
            if ($temporaryPath !== false) {
                $disk->delete($temporaryPath);
            }
        }

        $this->update([
            'size' => (int) filesize($disk->path($this->path())),
            'mime_type' => (string) $file->getMimeType(),
            'replaced_at' => now(),
        ]);
    }

    /**
     * A tidy file name that isn't used yet, numbered ("menu-2.pdf") if it would be.
     */
    public static function uniqueFilename(string $name, string $extension): string
    {
        $baseName = Str::slug($name) ?: 'file';
        $filename = "{$baseName}.{$extension}";

        for ($number = 2; self::isFilenameTaken($filename); $number++) {
            $filename = "{$baseName}-{$number}.{$extension}";
        }

        return $filename;
    }

    private static function isFilenameTaken(string $filename): bool
    {
        return self::query()->where('filename', $filename)->exists()
            || Storage::disk(self::DISK)->exists(self::DIRECTORY.'/'.$filename);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
    }

    /**
     * The kind of file, as visitors see it beside a link, e.g. "PDF" or "Word".
     */
    public function typeLabel(): string
    {
        return self::TYPES[$this->extension()]['label'] ?? strtoupper($this->extension());
    }

    /**
     * Its type and size, e.g. "PDF, 240 KB".
     */
    public function details(): string
    {
        return $this->typeLabel().', '.Number::fileSize($this->size, maxPrecision: 1);
    }

    /**
     * Where the file is on the disk.
     */
    public function path(): string
    {
        return self::DIRECTORY.'/'.$this->filename;
    }

    /**
     * The address of the file on the site, without the domain, with its version
     * once it's been replaced, so browsers fetch the new file rather than one
     * they saved earlier.
     */
    public function url(): string
    {
        return Storage::disk(self::DISK)->url($this->path().($this->replaced_at ? '?v='.$this->replaced_at->getTimestamp() : ''));
    }

    public function fileExists(): bool
    {
        return Storage::disk(self::DISK)->exists($this->path());
    }

    /**
     * The shortcode that links to the file from a page.
     */
    public function shortcode(): string
    {
        return '[file:'.$this->filename.']';
    }

    /**
     * Where the file is linked from, by name, e.g. "Page: Our menu".
     *
     * @return Collection<int, string>
     */
    public function usages(): Collection
    {
        return $this->contentUsing($this->filename);
    }

    /**
     * The library file name a reference in content points to: "menu.pdf",
     * "files/menu.pdf" and "/uploads/files/menu.pdf" all mean "menu.pdf".
     * Anything else, such as "/downloads/files/menu.pdf", isn't in the library.
     */
    public static function nameFromReference(string $reference): ?string
    {
        $name = strtolower(urldecode(ltrim(parse_url($reference, PHP_URL_PATH) ?: $reference, '/')));
        $name = preg_replace('#^(uploads/)?'.self::DIRECTORY.'/#', '', $name);

        return preg_match('/^[a-z0-9][a-z0-9._-]*\.[a-z0-9]+$/', $name) ? $name : null;
    }
}
