<?php

namespace Tests\Feature;

use App\Markdown;
use App\Models\Card;
use App\Models\Entry;
use App\Models\Image;
use App\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImageShortcodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Image::factory()->create(['filename' => 'team.jpg', 'alt_text' => 'The whole team', 'width' => 1200, 'height' => 800]);
    }

    public function test_the_first_picture_in_a_pages_content_loads_straight_away(): void
    {
        $html = (new Entry(['body' => "[image:team.jpg]\n\nText.\n\n[image:team.jpg|400]"]))->bodyHtml();

        $this->assertStringContainsString('<img src="/uploads/images/team.jpg" alt="The whole team" width="1200" height="800">', $html);
        $this->assertStringContainsString('style="--img-width: 400px" loading="lazy">', $html);
    }

    public function test_a_page_whose_first_picture_already_loads_straight_away_is_left_as_it_is(): void
    {
        $html = (new Entry(['body' => "<img src=\"/banner.png\" alt=\"\">\n\n[image:team.jpg]"]))->bodyHtml();

        $this->assertStringContainsString('height="800" loading="lazy">', $html);
    }

    public function test_the_shortcode_places_a_library_image_with_its_saved_description_and_size(): void
    {
        $this->assertSame(
            '<p><img src="/uploads/images/team.jpg" alt="The whole team" width="1200" height="800" loading="lazy"></p>',
            trim(Markdown::toHtml('[image:team.jpg]')),
        );
    }

    public function test_width_alignment_and_description_can_come_in_any_order(): void
    {
        $html = Markdown::toHtml('[image: team.jpg | Our team | right | 400 ]');

        $this->assertStringContainsString(
            '<img src="/uploads/images/team.jpg" alt="Our team" title="Our team" class="img-right" width="400" height="267" style="--img-width: 400px" loading="lazy">',
            $html,
        );
    }

    public function test_a_percentage_width_or_exact_size_can_be_given(): void
    {
        $this->assertStringContainsString('style="--img-width: 50%"', Markdown::toHtml('[image:team.jpg|50%]'));
        $this->assertStringContainsString('width="300" height="100" style="--img-width: 300px"', Markdown::toHtml('[image:team.jpg|300x100]'));
    }

    public function test_text_beside_an_image_stays_in_the_same_paragraph_so_it_wraps_around_it(): void
    {
        $html = Markdown::toHtml("[image:team.jpg|left]\nWe've worked together since 2010.");

        $this->assertMatchesRegularExpression('#^<p><img [^>]*class="img-left"[^>]*>\s*We\'ve worked together since 2010.</p>$#', trim($html));
    }

    public function test_a_full_url_can_be_used_for_an_image_outside_the_library(): void
    {
        $this->assertStringContainsString(
            '<img src="https://example.com/photo.png" alt="A photo" title="A photo" class="img-center" loading="lazy">',
            Markdown::toHtml('[image:https://example.com/photo.png|center|A photo]'),
        );
    }

    public function test_a_shortcode_for_an_image_not_in_the_library_is_left_as_written(): void
    {
        $html = Markdown::toHtml('See [image:missing.jpg|right] here.');

        $this->assertStringContainsString('<p>See [image:missing.jpg|right] here.</p>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_ordinary_markdown_images_find_their_file_in_the_library(): void
    {
        $this->assertStringContainsString(
            '<img src="/uploads/images/team.jpg" alt="The whole team" width="1200" height="800" loading="lazy">',
            Markdown::toHtml('![](team.jpg)'),
        );

        $this->assertStringContainsString(
            '<img src="/uploads/images/team.jpg" alt="Us" title="Us" width="1200" height="800" loading="lazy">',
            Markdown::toHtml('![Us](/storage/images/team.jpg)'),
        );

        $this->assertStringContainsString(
            'src="/uploads/images/team.jpg"',
            Markdown::toHtml('![](/uploads/images/team.jpg)'),
        );
    }

    public function test_ordinary_markdown_images_with_a_full_url_are_left_to_markdown(): void
    {
        $this->assertStringContainsString(
            '<img src="https://example.com/a.png" alt="Remote" />',
            Markdown::toHtml('![Remote](https://example.com/a.png)'),
        );
    }

    public function test_images_work_in_pages_modules_and_cards(): void
    {
        Image::factory()->create(['filename' => 'in-module.png']);
        Image::factory()->create(['filename' => 'in-card.png']);
        Module::factory()->create(['slug' => 'hero', 'body' => '[image:in-module.png]']);
        Card::factory()->create(['slug' => 'staff', 'body' => '![](in-card.png)']);
        Entry::factory()->published()->create(['slug' => 'home', 'body' => "[image:team.jpg]\n\n[module:hero]\n\n[card:staff]"]);

        $this->get('/')
            ->assertOk()
            ->assertSee('src="/uploads/images/team.jpg"', false)
            ->assertSee('src="/uploads/images/in-module.png"', false)
            ->assertSee('src="/uploads/images/in-card.png"', false);
    }

    public function test_descriptions_are_escaped(): void
    {
        $html = Markdown::toHtml('[image:team.jpg|"><script>alert(1)</script>]');

        $this->assertStringNotContainsString('<script>', $html);
    }
}
