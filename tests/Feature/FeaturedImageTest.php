<?php

namespace Tests\Feature;

use App\EntryType;
use App\Filament\Resources\Entries\Pages\EditEntry;
use App\Models\Card;
use App\Models\Entry;
use App\Models\Image;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeaturedImageTest extends TestCase
{
    use RefreshDatabase;

    private const THREE_IMAGES = "[image:first.jpg|400|left]\n\nText.\n\n![Second](second.jpg)\n\n[image:third.jpg]";

    protected function setUp(): void
    {
        parent::setUp();

        Image::factory()->create(['filename' => 'first.jpg', 'width' => 1200, 'height' => 800]);
        Image::factory()->create(['filename' => 'second.jpg', 'width' => 1600, 'height' => 900]);
        Image::factory()->create(['filename' => 'third.jpg']);
        Image::factory()->create(['filename' => 'in-card.jpg']);
        Theme::current()->update(['logo_path' => 'theme/logo.png']);
    }

    public function test_a_page_is_shared_with_its_first_image_and_its_size(): void
    {
        Entry::factory()->published()->create(['slug' => 'about', 'body' => self::THREE_IMAGES]);

        $this->get('/about')
            ->assertSee('<meta property="og:image" content="'.url('uploads/images/first.jpg').'">', false)
            ->assertSee('<meta property="og:image:width" content="1200">', false)
            ->assertSee('<meta property="og:image:height" content="800">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta property="og:url" content="'.url('about').'">', false)
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertDontSee('og:image" content="'.url('uploads/theme/logo.png'), false);
    }

    public function test_a_chosen_featured_image_is_shared_and_shown_in_the_blog_list(): void
    {
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news']);
        Entry::factory()->post($blog)->published()->create(['slug' => 'story', 'body' => self::THREE_IMAGES, 'featured_image' => 'second.jpg']);

        $this->get('/news/story')
            ->assertSee('<meta property="og:image" content="'.url('uploads/images/second.jpg').'">', false)
            ->assertSee('<meta property="og:type" content="article">', false);

        $this->get('/news')->assertSee('<img src="/uploads/images/second.jpg" alt="">', false);
    }

    public function test_a_featured_image_no_longer_in_the_content_falls_back_to_the_first(): void
    {
        Entry::factory()->published()->create(['slug' => 'about', 'body' => '[image:third.jpg]', 'featured_image' => 'second.jpg']);

        $this->get('/about')->assertSee('<meta property="og:image" content="'.url('uploads/images/third.jpg').'">', false);
    }

    public function test_images_in_cards_on_the_page_are_not_used_and_a_page_without_images_shares_the_logo(): void
    {
        Card::factory()->create(['slug' => 'promo', 'body' => '[image:in-card.jpg]']);
        Entry::factory()->published()->create(['slug' => 'about', 'body' => 'No pictures of its own. [card:promo]']);

        $this->get('/about')
            ->assertSee('<meta property="og:image" content="'.url('uploads/theme/logo.png').'">', false)
            ->assertSee('<meta name="twitter:card" content="summary">', false)
            ->assertDontSee('og:image" content="'.url('uploads/images/in-card.jpg'), false);
    }

    public function test_the_featured_image_choices_are_only_the_images_in_the_content(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Entry::factory()->create(['type' => EntryType::Page, 'body' => self::THREE_IMAGES.' [card:promo]']);

        $choices = Livewire::test(EditEntry::class, ['record' => $page->getRouteKey()])
            ->instance()->form->getComponent('featured_image')->getOptions();

        $this->assertSame(['first.jpg', 'second.jpg', 'third.jpg'], array_keys($choices));
    }

    public function test_the_featured_image_is_saved_and_cleared_when_taken_out_of_the_content(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Entry::factory()->create(['type' => EntryType::Page, 'body' => self::THREE_IMAGES]);

        Livewire::test(EditEntry::class, ['record' => $page->getRouteKey()])
            ->fillForm(['featured_image' => 'second.jpg'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('second.jpg', $page->refresh()->featured_image);

        Livewire::test(EditEntry::class, ['record' => $page->getRouteKey()])
            ->fillForm(['body' => '[image:first.jpg]'])
            ->assertSchemaStateSet(['featured_image' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($page->refresh()->featured_image);
    }
}
