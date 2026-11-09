<?php

namespace App\Models;

use App\Models\Concerns\UsedInContent;
use Database\Factories\ImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An image in the library, stored in public/uploads/images, where the web server serves it directly.
 *
 * Uploads keep a tidied version of their own name ("Team Photo.JPG" becomes
 * "team-photo.jpg"). A name that's taken is numbered ("team-photo-2.jpg"), so an
 * upload never replaces an image that's already there. Replacing one is a separate,
 * deliberate step that keeps its name, so everywhere it's used keeps working.
 */
#[Fillable([
    'filename',
    'alt_text',
    'width',
    'height',
    'size',
    'mime_type',
    'replaced_at',
])]
class Image extends Model
{
    /** @use HasFactory<ImageFactory> */
    use HasFactory;

    use UsedInContent;

    public const DISK = 'uploads';

    public const DIRECTORY = 'images';

    /**
     * The types of image that may be uploaded, with the extension each is saved under.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private const NOT_AN_IMAGE_MESSAGE = 'The file isn\'t a JPG, PNG, GIF or WebP image.';

    /**
     * Above this, an image is pointed out as one that may load slowly.
     */
    private const LARGE_FILE_BYTES = 500 * 1024;

    protected static function booted(): void
    {
        static::deleting(function (Image $image): bool {
            return $image->usages()->isEmpty();
        });

        static::deleted(function (Image $image): void {
            Storage::disk(self::DISK)->delete($image->path());
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
            'width' => 'integer',
            'height' => 'integer',
            'size' => 'integer',
            'replaced_at' => 'datetime',
        ];
    }

    /**
     * Add an uploaded file to the library, exactly as it is, under a name no other image has.
     *
     * The upload screens only take images, but the file is checked here too, so
     * nothing else (such as a script) can ever reach the public uploads folder.
     *
     * @throws RuntimeException With a message for the person uploading it.
     */
    public static function storeUpload(UploadedFile $file): self
    {
        $mimeType = (string) $file->getMimeType();
        $extension = self::TYPES[$mimeType] ?? throw new RuntimeException(self::NOT_AN_IMAGE_MESSAGE);
        $filename = self::uniqueFilename(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), $extension);
        $path = $file->storeAs(self::DIRECTORY, $filename, self::DISK);

        $absolutePath = Storage::disk(self::DISK)->path($path);
        [$width, $height] = getimagesize($absolutePath) ?: [0, 0];

        return self::query()->create([
            'filename' => $filename,
            'width' => $width,
            'height' => $height,
            'size' => (int) filesize($absolutePath),
            'mime_type' => $mimeType,
        ]);
    }

    /**
     * Swap in a new file under this image's name. The new file is put in place only
     * once it's safely on the disk, so a failure leaves the old one untouched.
     *
     * @throws RuntimeException With a message for the person replacing it.
     */
    public function replaceWith(UploadedFile $file): void
    {
        $mimeType = (string) $file->getMimeType();

        if (self::TYPES[$mimeType] ?? null) {
            if (self::TYPES[$mimeType] !== $this->normalizedExtension()) {
                throw new RuntimeException(sprintf(
                    'This image is a %s, so the new file must be too. To use a %s, upload it as a new image.',
                    strtoupper($this->normalizedExtension()),
                    strtoupper(self::TYPES[$mimeType]),
                ));
            }
        } else {
            throw new RuntimeException(self::NOT_AN_IMAGE_MESSAGE);
        }

        $dimensions = @getimagesize($file->getRealPath());

        if ($dimensions === false) {
            throw new RuntimeException('The new file couldn\'t be read as an image.');
        }

        $disk = Storage::disk(self::DISK);
        $temporaryPath = $file->storeAs(self::DIRECTORY, '.replacing-'.Str::random(32), self::DISK);

        try {
            if ($temporaryPath === false || ! rename($disk->path($temporaryPath), $disk->path($this->path()))) {
                throw new RuntimeException('The new file couldn\'t be saved, so the image hasn\'t changed.');
            }
        } finally {
            if ($temporaryPath !== false) {
                $disk->delete($temporaryPath);
            }
        }

        $this->update([
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'size' => (int) filesize($disk->path($this->path())),
            'mime_type' => $mimeType,
            'replaced_at' => now(),
        ]);
    }

    /**
     * Bring the stored details up to date with the file, for one replaced by FTP.
     *
     * @return bool Whether anything had changed.
     */
    public function refreshFromFile(): bool
    {
        $absolutePath = Storage::disk(self::DISK)->path($this->path());
        [$width, $height] = @getimagesize($absolutePath) ?: [$this->width, $this->height];

        $this->fill(['width' => $width, 'height' => $height, 'size' => (int) filesize($absolutePath)]);

        if (! $this->isDirty()) {
            return false;
        }

        $this->replaced_at = now();

        return $this->save();
    }

    /**
     * Whether the file is big enough to slow a page down. It's only ever pointed out;
     * images are kept exactly as they're uploaded.
     */
    public function isLarge(): bool
    {
        return $this->size > self::LARGE_FILE_BYTES;
    }

    public function fileExists(): bool
    {
        return Storage::disk(self::DISK)->exists($this->path());
    }

    /**
     * The file name's extension, with "jpeg" counted as "jpg".
     */
    private function normalizedExtension(): string
    {
        $extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    /**
     * A tidy file name that isn't used yet, numbered ("photo-2.jpg") if it would be.
     */
    public static function uniqueFilename(string $name, string $extension): string
    {
        $baseName = Str::slug($name) ?: 'image';
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

    /**
     * Where the image is on the disk.
     */
    public function path(): string
    {
        return self::DIRECTORY.'/'.$this->filename;
    }

    /**
     * The address of the image on the site, without the domain, so pages keep
     * working if the site moves.
     */
    public function url(): string
    {
        return Storage::disk(self::DISK)->url($this->versionedPath());
    }

    /**
     * Where the image is on the disk, with its version once it's been replaced, so
     * browsers fetch the new file rather than showing the one they already have.
     */
    public function versionedPath(): string
    {
        return $this->path().($this->replaced_at ? '?v='.$this->replaced_at->getTimestamp() : '');
    }

    /**
     * The shortcode that places the image in a page.
     */
    public function shortcode(): string
    {
        return '[image:'.$this->filename.']';
    }

    /**
     * Where the image is used, by name: the pages, posts, modules and cards whose
     * content uses it, the sliders that show it and the modules with it as their
     * background, e.g. "Page: About us".
     *
     * @return Collection<int, string>
     */
    public function usages(): Collection
    {
        return $this->contentUsing($this->filename)
            ->merge(Slider::query()->whereRelation('slides', 'image_id', $this->id)->pluck('name')
                ->map(fn (string $name): string => 'Slider: '.$name))
            ->merge(Module::query()->where('background_image_id', $this->id)->pluck('name')
                ->map(fn (string $name): string => 'Module: '.$name))
            ->unique()
            ->values();
    }

    /**
     * The library file name that a reference in content points to: "team.jpg",
     * "images/team.jpg", "/uploads/images/team.jpg" and the older
     * "/storage/images/team.jpg" all mean "team.jpg".
     */
    public static function nameFromReference(string $reference): ?string
    {
        $name = strtolower(urldecode(ltrim(parse_url($reference, PHP_URL_PATH) ?: $reference, '/')));
        $name = preg_replace('#^(storage|uploads)/#', '', $name);
        $name = Str::after($name, self::DIRECTORY.'/');

        return preg_match('/^[a-z0-9][a-z0-9._-]*\.[a-z0-9]+$/', $name) ? $name : null;
    }
}
