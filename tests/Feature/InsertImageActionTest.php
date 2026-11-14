<?php

namespace Tests\Feature;

use App\Filament\Resources\Cards\Pages\CreateCard;
use App\Filament\Resources\Entries\Pages\CreateEntry;
use App\Filament\Resources\Modules\Pages\CreateModule;
use App\Models\Image;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InsertImageActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Image::DISK, ['url' => '/uploads']);
        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function pagesWithAnEditor(): array
    {
        return [
            'pages and posts' => [CreateEntry::class],
            'cards' => [CreateCard::class],
            'modules' => [CreateModule::class],
        ];
    }

    #[DataProvider('pagesWithAnEditor')]
    public function test_every_content_editor_offers_it(string $page): void
    {
        Livewire::test($page)->assertActionExists(self::insertImage());
    }

    public function test_choosing_a_library_image_inserts_its_shortcode_and_saves_its_alt_text(): void
    {
        $image = Image::factory()->create(['filename' => 'team.jpg', 'alt_text' => 'Old words']);

        Livewire::test(CreateEntry::class)
            ->callAction(self::insertImage(), data: ['source' => 'library', 'image' => $image->id, 'alt_text' => 'Our team'])
            ->assertHasNoFormErrors()
            ->assertDispatched('insert-into-editor', text: '[image:team.jpg]');

        $this->assertSame('Our team', $image->refresh()->alt_text);
    }

    public function test_an_image_beyond_the_listed_ones_can_still_be_chosen(): void
    {
        $oldest = Image::factory()->create(['filename' => 'oldest.jpg', 'created_at' => now()->subYear()]);
        Image::factory()->count(50)->create();

        Livewire::test(CreateEntry::class)
            ->callAction(self::insertImage(), data: ['source' => 'library', 'image' => $oldest->id])
            ->assertHasNoFormErrors()
            ->assertDispatched('insert-into-editor', text: '[image:oldest.jpg]');
    }

    public function test_a_library_image_must_be_chosen(): void
    {
        Livewire::test(CreateEntry::class)
            ->callAction(self::insertImage(), data: ['source' => 'library'])
            ->assertHasFormErrors(['image' => 'required'])
            ->assertNotDispatched('insert-into-editor');
    }

    public function test_uploading_adds_the_image_to_the_library_with_its_alt_text(): void
    {
        Livewire::test(CreateEntry::class)
            ->callAction(self::insertImage(), data: [
                'source' => 'upload',
                'file' => UploadedFile::fake()->image('Team Photo.jpg', 40, 30),
                'alt_text' => 'Our team',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Added to the image library as team-photo.jpg')
            ->assertDispatched('insert-into-editor', text: '[image:team-photo.jpg]');

        $image = Image::query()->sole();
        $this->assertSame('Our team', $image->alt_text);
        $this->assertSame(40, $image->width);
        Storage::disk(Image::DISK)->assertExists('images/team-photo.jpg');
    }

    public function test_an_upload_never_replaces_an_image_with_the_same_name(): void
    {
        $existing = Image::factory()->create(['filename' => 'team.jpg', 'alt_text' => 'The original']);

        Livewire::test(CreateEntry::class)
            ->callAction(self::insertImage(), data: ['source' => 'upload', 'file' => UploadedFile::fake()->image('team.jpg', 40, 30)])
            ->assertHasNoFormErrors()
            ->assertDispatched('insert-into-editor', text: '[image:team-2.jpg]');

        $this->assertSame('The original', $existing->refresh()->alt_text);
    }

    public function test_an_image_address_only_writes_a_shortcode(): void
    {
        Livewire::test(CreateEntry::class)
            ->callAction(self::insertImage(), data: [
                'source' => 'address',
                'address' => 'https://bucket.s3.amazonaws.com/photo.jpg',
                'alt_text' => 'Sunset | over the bay]',
            ])
            ->assertHasNoFormErrors()
            ->assertDispatched('insert-into-editor', text: '[image:https://bucket.s3.amazonaws.com/photo.jpg|Sunset   over the bay]');

        $this->assertSame(0, Image::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableAddresses(): array
    {
        return [
            'not a web address' => ['javascript:alert(1)'],
            'no scheme' => ['bucket.s3.amazonaws.com/photo.jpg'],
            'a character that would end the shortcode' => ['https://example.com/a]b.jpg'],
            'a space' => ['https://example.com/a b.jpg'],
        ];
    }

    #[DataProvider('unusableAddresses')]
    public function test_an_unusable_address_is_refused(string $address): void
    {
        Livewire::test(CreateEntry::class)
            ->callAction(self::insertImage(), data: ['source' => 'address', 'address' => $address])
            ->assertHasFormErrors(['address'])
            ->assertNotDispatched('insert-into-editor');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function altTextReadAsSizeOrPosition(): array
    {
        return [
            'an alignment' => ['Left'],
            'a year, read as a width' => ['2024'],
            'a percentage' => ['50%'],
        ];
    }

    #[DataProvider('altTextReadAsSizeOrPosition')]
    public function test_alt_text_for_an_address_that_would_be_misread_is_refused(string $altText): void
    {
        Livewire::test(CreateEntry::class)
            ->callAction(self::insertImage(), data: ['source' => 'address', 'address' => 'https://example.com/photo.jpg', 'alt_text' => $altText])
            ->assertHasFormErrors(['alt_text'])
            ->assertNotDispatched('insert-into-editor');
    }

    public function test_alt_text_with_more_words_and_library_alt_text_are_accepted(): void
    {
        Livewire::test(CreateEntry::class)
            ->callAction(self::insertImage(), data: ['source' => 'address', 'address' => 'https://example.com/photo.jpg', 'alt_text' => 'Left side of the building'])
            ->assertHasNoFormErrors()
            ->assertDispatched('insert-into-editor', text: '[image:https://example.com/photo.jpg|Left side of the building]');

        $image = Image::factory()->create(['filename' => 'team.jpg']);

        Livewire::test(CreateEntry::class)
            ->callAction(self::insertImage(), data: ['source' => 'library', 'image' => $image->id, 'alt_text' => 'Left'])
            ->assertHasNoFormErrors();
    }

    private static function insertImage(): TestAction
    {
        return TestAction::make('insertImage')->schemaComponent('body');
    }
}
