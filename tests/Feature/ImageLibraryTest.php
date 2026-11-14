<?php

namespace Tests\Feature;

use App\EntryType;
use App\Models\Card;
use App\Models\Entry;
use App\Models\Image;
use App\Models\Module;
use Filament\Forms\Components\MarkdownEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Tests\TestCase;

class ImageLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Image::DISK, ['url' => '/uploads']);
    }

    public function test_only_the_librarys_own_addresses_count_as_library_images(): void
    {
        foreach (['team.jpg', 'images/team.jpg', '/uploads/images/team.jpg', '/storage/images/team.jpg', '/uploads/images/team.jpg?v=5'] as $reference) {
            $this->assertSame('team.jpg', Image::nameFromReference($reference), $reference);
        }

        foreach (['/assets/images/team.jpg', 'theme/images/team.jpg', '/uploads/files/images/team.jpg'] as $reference) {
            $this->assertNull(Image::nameFromReference($reference), $reference);
        }
    }

    public function test_an_image_that_cannot_be_saved_is_not_added_to_the_library(): void
    {
        // Kept in a variable: the fake's file is deleted once nothing holds it
        $fake = UploadedFile::fake()->image('team.png');
        $upload = new class($fake->getPathname(), 'team.png', null, null, true) extends UploadedFile
        {
            public function storeAs($path, $name = null, $options = []): string|false
            {
                return false;
            }
        };

        try {
            Image::storeUpload($upload);
            $this->fail('The upload was added without its file.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('The file couldn\'t be saved', $exception->getMessage());
        }

        $this->assertSame(0, Image::query()->count());
    }

    public function test_an_upload_keeps_a_tidied_version_of_its_name(): void
    {
        $image = Image::storeUpload(UploadedFile::fake()->image('Team Photo.JPG', 800, 600));

        $this->assertSame('team-photo.jpg', $image->filename);
        $this->assertSame([800, 600], [$image->width, $image->height]);
        $this->assertSame('image/jpeg', $image->mime_type);
        $this->assertSame('/uploads/images/team-photo.jpg', $image->url());
        Storage::disk(Image::DISK)->assertExists('images/team-photo.jpg');
        $this->assertSame(public_path('uploads'), config('filesystems.disks.uploads.root'));
    }

    public function test_an_upload_never_replaces_an_image_with_the_same_name(): void
    {
        $first = Image::storeUpload(UploadedFile::fake()->image('team.png', 10, 10));
        $second = Image::storeUpload(UploadedFile::fake()->image('team.png', 20, 20));
        $third = Image::storeUpload(UploadedFile::fake()->image('Team.png', 30, 30));

        $this->assertSame(['team.png', 'team-2.png', 'team-3.png'], [$first->filename, $second->filename, $third->filename]);
        $this->assertSame(10, getimagesize(Storage::disk(Image::DISK)->path('images/team.png'))[0]);
    }

    public function test_a_file_already_in_the_folder_is_not_replaced_either(): void
    {
        Storage::disk(Image::DISK)->put('images/logo.png', 'copied in by hand');

        $image = Image::storeUpload(UploadedFile::fake()->image('logo.png', 10, 10));

        $this->assertSame('logo-2.png', $image->filename);
        $this->assertSame('copied in by hand', Storage::disk(Image::DISK)->get('images/logo.png'));
    }

    public function test_a_file_that_isnt_an_image_is_never_stored(): void
    {
        try {
            Image::storeUpload(UploadedFile::fake()->createWithContent('shell.php', '<?php echo "hi";'));
            $this->fail('A file that isn\'t an image was stored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The file isn\'t a JPG, PNG, GIF or WebP image.', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk(Image::DISK)->allFiles());
        $this->assertSame(0, Image::query()->count());
    }

    public function test_images_are_stored_exactly_as_uploaded(): void
    {
        $upload = UploadedFile::fake()->image('wide.png', 2400, 600);
        $original = file_get_contents($upload->getRealPath());

        $image = Image::storeUpload($upload);

        $this->assertSame($original, Storage::disk(Image::DISK)->get('images/wide.png'));
        $this->assertSame([2400, 600], [$image->width, $image->height]);
    }

    public function test_usages_name_the_content_that_uses_the_image(): void
    {
        $image = Image::factory()->create(['filename' => 'team.jpg']);
        Entry::factory()->create(['title' => 'About us', 'body' => 'Meet us: [image:team.jpg|right]']);
        Entry::factory()->create(['type' => EntryType::Blog, 'title' => 'News', 'body' => '![](/storage/images/team.jpg)']);
        Module::factory()->create(['name' => 'Hero', 'body' => '![The team](team.jpg)']);
        Card::factory()->create(['name' => 'Staff', 'body' => '[image:TEAM.jpg]']);
        Entry::factory()->create(['title' => 'Unrelated', 'body' => '[image:my-team.jpg] [image:team.jpeg] [image:team.jpg.bak]']);

        $this->assertEqualsCanonicalizing(
            ['Page: About us', 'Blog: News', 'Module: Hero', 'Card: Staff'],
            $image->usages()->all(),
        );
    }

    public function test_an_image_in_use_cannot_be_deleted(): void
    {
        $image = Image::storeUpload(UploadedFile::fake()->image('team.jpg', 10, 10));
        Entry::factory()->create(['body' => '[image:team.jpg]']);

        $image->delete();

        $this->assertModelExists($image);
        Storage::disk(Image::DISK)->assertExists('images/team.jpg');
    }

    public function test_deleting_an_unused_image_removes_its_file(): void
    {
        $image = Image::storeUpload(UploadedFile::fake()->image('team.jpg', 10, 10));

        $image->delete();

        $this->assertModelMissing($image);
        Storage::disk(Image::DISK)->assertMissing('images/team.jpg');
    }

    public function test_content_editors_take_no_images_directly(): void
    {
        $editor = MarkdownEditor::make('body');

        $this->assertFalse($editor->hasFileAttachments());
        $this->assertNull($editor->saveUploadedFileAttachment(self::temporaryUpload(UploadedFile::fake()->image('Editor Shot.png', 50, 50))));
        $this->assertSame(0, Image::query()->count());
    }

    /**
     * A file as Livewire holds it between being uploaded and the form being saved.
     */
    private static function temporaryUpload(UploadedFile $file): TemporaryUploadedFile
    {
        Storage::fake(FileUploadConfiguration::disk());

        $name = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded($file);
        $file->storeAs(FileUploadConfiguration::directory(), $name, ['disk' => FileUploadConfiguration::disk()]);

        return TemporaryUploadedFile::createFromLivewire($name);
    }
}
