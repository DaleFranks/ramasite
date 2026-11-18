<?php

namespace Tests\Feature;

use App\Markdown;
use App\Models\Card;
use App\Models\Entry;
use App\Models\Image;
use App\Models\Module;
use App\Models\SocialLinkSet;
use App\SocialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_set_is_shown_in_the_line_where_its_shortcode_is_written(): void
    {
        SocialLinkSet::factory()->create(['slug' => 'main']);
        Entry::factory()->published()->create(['slug' => 'home', 'body' => 'Follow us: [social:main] for news.']);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder([
                '<p>Follow us: <span class="social-links social-dark" style="--social-size: 32px">',
                '<a href="https://www.facebook.com/example" class="social-link social-facebook" aria-label="Facebook"',
                'target="_blank" rel="me noopener"',
                '<svg aria-hidden="true" focusable="false" fill="currentColor"',
                'aria-label="Instagram"',
                '</span> for news.</p>',
            ], false);
    }

    public function test_an_alignment_gives_the_set_a_line_of_its_own(): void
    {
        SocialLinkSet::factory()->create(['slug' => 'main']);

        $this->assertStringContainsString('<p><span class="social-links social-dark align-center"', Markdown::toHtml('[social:main|center]'));
        $this->assertStringContainsString('<span class="social-links social-dark align-right"', Markdown::toHtml('[social: Main | RIGHT ]'));
        $this->assertStringContainsString('<span class="social-links social-dark" ', Markdown::toHtml('[social:main|middle]'));
    }

    public function test_a_set_can_be_placed_in_modules_cards_and_the_footer(): void
    {
        SocialLinkSet::factory()->create(['slug' => 'main']);
        Module::factory()->footer()->create(['body' => 'Find us: [social:main]']);
        Module::factory()->create(['slug' => 'banner', 'body' => '[social:main]']);
        Card::factory()->create(['slug' => 'contact', 'body' => '[social:main]']);
        Entry::factory()->published()->create(['slug' => 'home', 'body' => "[module:banner]\n\n[card:contact]"]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, '<span class="social-links'));
        $this->assertStringContainsString('<p>Find us: <span class="social-links', $html);
    }

    public function test_links_can_open_in_the_same_tab(): void
    {
        $set = SocialLinkSet::factory()->create(['opens_in_new_tab' => false]);

        $html = $set->html();

        $this->assertStringNotContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="me"', $html);
    }

    public function test_an_email_link_is_a_mailto_link_and_never_opens_a_new_tab(): void
    {
        $set = SocialLinkSet::factory()->create([
            'opens_in_new_tab' => true,
            'links' => [['service' => 'email', 'url' => 'hello@example.com']],
        ]);

        $html = $set->html();

        $this->assertStringContainsString('<a href="mailto:hello@example.com" class="social-link social-email" aria-label="Email"', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    public function test_brand_colors_give_each_logo_its_brands_color_and_email_the_link_color(): void
    {
        $set = SocialLinkSet::factory()->create([
            'logo_style' => 'color',
            'links' => [
                ['service' => 'facebook', 'url' => 'https://www.facebook.com/example'],
                ['service' => 'email', 'url' => 'hello@example.com'],
            ],
        ]);

        $html = $set->html();

        $this->assertStringContainsString('<span class="social-links social-color"', $html);
        $this->assertMatchesRegularExpression('/aria-label="Facebook"\s+style="--social-color: #0866FF"/', $html);
        $this->assertDoesNotMatchRegularExpression('/aria-label="Email"\s+style=/', $html);
    }

    public function test_a_custom_color_colors_the_whole_set(): void
    {
        $custom = SocialLinkSet::factory()->create(['logo_style' => 'custom', 'logo_color' => '#123abc']);
        $withoutColor = SocialLinkSet::factory()->create(['logo_style' => 'custom', 'logo_color' => null]);
        $darkWithColor = SocialLinkSet::factory()->create(['logo_style' => 'dark', 'logo_color' => '#123abc']);

        $this->assertStringContainsString('<span class="social-links social-custom" style="--social-size: 32px; --social-color: #123abc">', $custom->html());
        $this->assertStringContainsString('<span class="social-links social-custom" style="--social-size: 32px">', $withoutColor->html());
        $this->assertStringNotContainsString('#123abc', $darkWithColor->html());
    }

    public function test_the_logo_size_is_kept_within_its_range(): void
    {
        $this->assertStringContainsString('--social-size: 128px', SocialLinkSet::factory()->create(['logo_size' => 500])->html());
        $this->assertStringContainsString('--social-size: 24px', SocialLinkSet::factory()->create(['logo_size' => 10])->html());
        $this->assertStringContainsString('--social-size: 48px', SocialLinkSet::factory()->create(['logo_size' => 48])->html());
    }

    public function test_a_linkedin_link_shows_the_library_image_chosen_for_it(): void
    {
        $logo = Image::factory()->create(['filename' => 'linkedin-blue.png']);
        $set = SocialLinkSet::factory()->create([
            'logo_size' => 40,
            'links' => [['service' => 'linkedin', 'url' => 'https://www.linkedin.com/in/example', 'image_id' => $logo->id]],
        ]);

        $html = $set->html();

        $this->assertStringContainsString('aria-label="LinkedIn"', $html);
        $this->assertStringContainsString('<img src="'.$logo->url().'" alt="" width="40" height="40">', $html);
        $this->assertStringNotContainsString('<svg', $html);
    }

    public function test_links_that_cant_be_shown_are_skipped(): void
    {
        $set = SocialLinkSet::factory()->create([
            'links' => [
                ['service' => 'linkedin', 'url' => 'https://www.linkedin.com/in/example', 'image_id' => 999],
                ['service' => 'myspace', 'url' => 'https://myspace.com/example'],
                ['service' => 'facebook', 'url' => ''],
                ['service' => 'x', 'url' => 'javascript:alert(1)'],
                ['service' => 'github', 'url' => 'https://github.com/example'],
            ],
        ]);

        $this->assertSame([SocialService::GitHub], $set->socialLinks()->pluck('service')->all());
    }

    public function test_a_shortcode_naming_no_set_or_an_empty_one_is_shown_as_written(): void
    {
        SocialLinkSet::factory()->create(['slug' => 'empty', 'links' => []]);

        $this->assertStringContainsString('<p>See [social:missing] and [social:empty|center].</p>', Markdown::toHtml('See [social:missing] and [social:empty|center].'));
    }

    public function test_every_service_but_linkedin_has_a_built_in_logo_with_nothing_that_runs(): void
    {
        foreach (SocialService::cases() as $service) {
            if ($service->usesOwnLogo()) {
                $this->assertNull($service->logoSvg());

                continue;
            }

            $svg = (string) $service->logoSvg();
            $this->assertStringStartsWith('<svg aria-hidden="true" focusable="false" fill="currentColor" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">', $svg, $service->value);
            $this->assertDoesNotMatchRegularExpression('/<script|\son\w+=|href=/i', $svg, $service->value);
        }
    }

    public function test_an_image_used_as_the_linkedin_logo_cannot_be_deleted(): void
    {
        $logo = Image::factory()->create();
        $other = Image::factory()->create();
        SocialLinkSet::factory()->create([
            'name' => 'Main',
            'links' => [['service' => 'linkedin', 'url' => 'https://www.linkedin.com/in/example', 'image_id' => $logo->id]],
        ]);

        $logo->delete();

        $this->assertSame(['Social links: Main'], $logo->usages()->all());
        $this->assertModelExists($logo);
        $this->assertSame([], $other->usages()->all());
    }
}
