<?php

namespace Tests\Feature;

use App\Markdown;
use App\Models\Card;
use App\Models\Entry;
use App\Models\LibraryFile;
use App\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class FileLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(LibraryFile::DISK, ['url' => '/uploads']);
    }

    public static function pdf(string $name = 'Our Menu.PDF', string $content = "%PDF-1.4\n%\xe2\xe3\n1 0 obj<<>>endobj\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private static function zipContent(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('photo-list.txt', 'Photos from the opening night');
        $zip->close();

        return (string) file_get_contents($path);
    }

    /**
     * An upload whose type is read from what's in it, as a real upload's is.
     * (A fake upload's type is only guessed from its name.)
     */
    private static function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_only_the_librarys_own_addresses_count_as_library_files(): void
    {
        foreach (['menu.pdf', 'files/menu.pdf', '/uploads/files/menu.pdf', '/uploads/files/menu.pdf?v=5'] as $reference) {
            $this->assertSame('menu.pdf', LibraryFile::nameFromReference($reference), $reference);
        }

        foreach (['/downloads/files/menu.pdf', 'docs/files/menu.pdf'] as $reference) {
            $this->assertNull(LibraryFile::nameFromReference($reference), $reference);
        }
    }

    public function test_an_upload_keeps_a_tidied_version_of_its_name_and_its_size(): void
    {
        $file = LibraryFile::storeUpload(self::pdf());

        $this->assertSame('our-menu.pdf', $file->filename);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertSame(Storage::disk(LibraryFile::DISK)->size('files/our-menu.pdf'), $file->size);
        $this->assertSame('/uploads/files/our-menu.pdf', $file->url());
    }

    public function test_an_upload_never_replaces_a_file_with_the_same_name(): void
    {
        $first = LibraryFile::storeUpload(self::pdf('menu.pdf'));
        $second = LibraryFile::storeUpload(self::pdf('Menu.pdf'));

        $this->assertSame(['menu.pdf', 'menu-2.pdf'], [$first->filename, $second->filename]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allowedFiles(): array
    {
        return [
            'zip' => ['photos.zip', self::zipContent()],
            'csv' => ['prices.csv', "name,price\nTea,2.50\n"],
            'text' => ['notes.txt', "Opening hours\nMonday to Friday\n"],
        ];
    }

    #[DataProvider('allowedFiles')]
    public function test_the_allowed_types_can_be_uploaded(string $name, string $content): void
    {
        $file = LibraryFile::storeUpload(self::realUpload($name, $content));

        $this->assertSame($name, $file->filename);
        Storage::disk(LibraryFile::DISK)->assertExists('files/'.$name);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusedFiles(): array
    {
        return [
            'a web page' => ['page.html', '<html><body>Hi</body></html>', 'Files of this type can\'t be uploaded.'],
            'an SVG' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'Files of this type can\'t be uploaded.'],
            'a script' => ['shell.php', '<?php echo 1;', 'Files of this type can\'t be uploaded.'],
            'audio' => ['interview.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xff\xfb\x90\x00", 200), 'Files of this type can\'t be uploaded.'],
            'no extension' => ['README', 'Just text', 'Files of this type can\'t be uploaded.'],
            'a web page named as text' => ['notes.txt', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'This file doesn\'t look like a Text file inside'],
            'a script named as a PDF' => ['menu.pdf', '<?php echo 1;', 'This file doesn\'t look like a PDF file inside'],
        ];
    }

    #[DataProvider('refusedFiles')]
    public function test_other_types_and_disguised_files_are_refused(string $name, string $content, string $message): void
    {
        try {
            LibraryFile::storeUpload(self::realUpload($name, $content));
            $this->fail('The file was stored.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith($message, $exception->getMessage());
        }

        $this->assertSame([], Storage::disk(LibraryFile::DISK)->allFiles());
        $this->assertSame(0, LibraryFile::query()->count());
    }

    public function test_a_file_that_cannot_be_saved_is_not_added_to_the_library(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, "%PDF-1.4\n%\xe2\xe3\n1 0 obj<<>>endobj\n");
        $upload = new class($path, 'menu.pdf', null, null, true) extends UploadedFile
        {
            public function storeAs($path, $name = null, $options = []): string|false
            {
                return false;
            }
        };

        try {
            LibraryFile::storeUpload($upload);
            $this->fail('The upload was added without its file.');
        } catch (RuntimeException $exception) {
            $this->assertSame(LibraryFile::NOT_SAVED_MESSAGE, $exception->getMessage());
        }

        $this->assertSame(0, LibraryFile::query()->count());
    }

    public function test_the_type_message_lists_what_can_be_uploaded(): void
    {
        $this->assertSame(
            'PDF, Word, Excel, PowerPoint, OpenDocument text, OpenDocument spreadsheet, OpenDocument presentation, Text, CSV and ZIP files',
            LibraryFile::typeList(),
        );
    }

    public function test_replacing_keeps_the_name_and_updates_the_size_and_address(): void
    {
        $file = LibraryFile::storeUpload(self::pdf('menu.pdf'));
        $this->travelTo('2026-11-06 12:00:00');

        $file->replaceWith(self::pdf('new-menu.pdf', "%PDF-1.4\n".str_repeat('x', 5000)));

        $this->assertSame('menu.pdf', $file->filename);
        $this->assertSame(Storage::disk(LibraryFile::DISK)->size('files/menu.pdf'), $file->size);
        $this->assertGreaterThan(5000, $file->size);
        $this->assertSame('/uploads/files/menu.pdf?v='.now()->getTimestamp(), $file->url());
        $this->assertSame(['files/menu.pdf'], Storage::disk(LibraryFile::DISK)->allFiles());
    }

    public function test_a_file_can_only_be_replaced_by_one_of_the_same_type(): void
    {
        $file = LibraryFile::storeUpload(self::pdf('menu.pdf'));
        $original = Storage::disk(LibraryFile::DISK)->get('files/menu.pdf');

        $this->expectExceptionMessage('This is a PDF file, so the new file must be too.');

        try {
            $file->replaceWith(UploadedFile::fake()->createWithContent('menu.csv', "a,b\n"));
        } finally {
            $this->assertSame($original, Storage::disk(LibraryFile::DISK)->get('files/menu.pdf'));
        }
    }

    public function test_the_details_give_the_type_and_a_readable_size(): void
    {
        $this->assertSame('PDF, 240 KB', LibraryFile::factory()->make(['filename' => 'menu.pdf', 'size' => 245_760])->details());
        $this->assertSame('Word, 1.2 MB', LibraryFile::factory()->make(['filename' => 'form.docx', 'size' => 1_258_291])->details());
        $this->assertSame('ZIP, 11.4 MB', LibraryFile::factory()->make(['filename' => 'photos.zip', 'size' => 12_000_000])->details());
    }

    public function test_usages_name_the_content_that_links_to_the_file(): void
    {
        $file = LibraryFile::factory()->create(['filename' => 'menu.pdf']);
        Entry::factory()->create(['title' => 'Food', 'body' => 'See [file:menu.pdf|our menu|details].']);
        Module::factory()->create(['name' => 'Hours', 'body' => '[Menu](/uploads/files/menu.pdf)']);
        Card::factory()->create(['name' => 'Drinks', 'body' => '[file:drinks-menu.pdf]']);

        $this->assertSame(['Page: Food', 'Module: Hours'], $file->usages()->all());
    }

    public function test_a_file_in_use_cannot_be_deleted_and_an_unused_one_takes_its_file_with_it(): void
    {
        $used = LibraryFile::storeUpload(self::pdf('menu.pdf'));
        $unused = LibraryFile::storeUpload(self::pdf('old.pdf'));
        Entry::factory()->create(['body' => '[file:menu.pdf]']);

        $used->delete();
        $unused->delete();

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
        $this->assertSame(['files/menu.pdf'], Storage::disk(LibraryFile::DISK)->allFiles());
    }

    public function test_the_shortcode_links_to_the_file_with_the_text_given(): void
    {
        LibraryFile::factory()->create(['filename' => 'menu.pdf', 'size' => 245_760]);

        $this->assertSame(
            '<p>Download <a href="/uploads/files/menu.pdf">our menu</a> today.</p>',
            trim(Markdown::toHtml('Download [file:menu.pdf|our menu] today.')),
        );
    }

    public function test_details_add_the_type_and_size_as_they_are_now(): void
    {
        $file = LibraryFile::factory()->create(['filename' => 'menu.pdf', 'size' => 245_760]);

        $this->assertStringContainsString(
            '<a href="/uploads/files/menu.pdf">Our menu</a> <span class="file-details">(PDF, 240 KB)</span>',
            Markdown::toHtml('[file: menu.pdf | details | Our menu ]'),
        );

        $file->update(['size' => 1_258_291]);

        $this->assertStringContainsString('(PDF, 1.2 MB)', Markdown::toHtml('[file:menu.pdf|Our menu|details]'));
    }

    public function test_a_link_can_read_details_with_a_capital_letter(): void
    {
        LibraryFile::factory()->create(['filename' => 'menu.pdf', 'size' => 245_760]);

        $this->assertSame(
            '<p><a href="/uploads/files/menu.pdf">Details</a></p>',
            trim(Markdown::toHtml('[file:menu.pdf|Details]')),
        );
    }

    public function test_without_text_the_link_shows_the_files_name(): void
    {
        LibraryFile::factory()->create(['filename' => 'menu.pdf']);

        $this->assertStringContainsString('<a href="/uploads/files/menu.pdf">menu.pdf</a>', Markdown::toHtml('[file:menu.pdf]'));
    }

    public function test_link_text_is_escaped(): void
    {
        LibraryFile::factory()->create(['filename' => 'menu.pdf']);

        $this->assertStringContainsString('>&lt;b&gt;Menu&lt;/b&gt;</a>', Markdown::toHtml('[file:menu.pdf|<b>Menu</b>]'));
    }

    public function test_a_missing_file_is_left_as_written(): void
    {
        $this->assertSame('<p>See [file:gone.pdf|the menu].</p>', trim(Markdown::toHtml('See [file:gone.pdf|the menu].')));
    }

    public function test_file_links_work_in_cards_and_modules_and_in_a_site_folder(): void
    {
        LibraryFile::factory()->create(['filename' => 'menu.pdf']);
        Storage::fake(LibraryFile::DISK, ['url' => '/blog/uploads']);

        $this->assertStringContainsString('<a href="/blog/uploads/files/menu.pdf">Menu</a>', Card::factory()->create(['body' => '[file:menu.pdf|Menu]'])->html());
    }

    public function test_the_blog_excerpt_keeps_a_file_links_text(): void
    {
        $entry = new Entry(['body' => 'Download [file:menu.pdf|our new menu|details] today. Or [file:prices.pdf] instead.']);

        $this->assertSame('Download our new menu today. Or prices.pdf instead.', $entry->excerpt());
    }
}
