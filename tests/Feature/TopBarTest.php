<?php

namespace Tests\Feature;

use App\Filament\Pages\ThemeSettings;
use App\Models\Entry;
use App\Models\SocialLinkSet;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TopBarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Entry::factory()->published()->create(['slug' => 'about']);
    }

    public function test_the_top_bar_is_set_on_the_theme_screen(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ThemeSettings::class)
            ->fillForm([
                'top_bar_text' => 'Call us: [(760) 555-0100](tel:+17605550100)',
                'top_bar_style' => 'primary',
                'top_bar_alignment' => 'right',
                'top_bar_stays_visible' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $theme = Theme::current();
        $this->assertSame('Call us: [(760) 555-0100](tel:+17605550100)', $theme->top_bar_text);
        $this->assertSame('primary', $theme->top_bar_style->value);
        $this->assertSame('right', $theme->top_bar_alignment->value);
        $this->assertFalse($theme->top_bar_stays_visible);
    }

    public function test_every_page_shows_the_top_bar_above_the_logo_and_menu(): void
    {
        Theme::current()->update(['top_bar_text' => 'Call us: [(760) 555-0100](tel:+17605550100)']);

        $this->get('/about')
            ->assertOk()
            ->assertSee('<body class="logo-left has-top-bar', false)
            ->assertSeeInOrder([
                '<div class="top-bar theme-dark align-center">',
                'Call us: <a href="tel:+17605550100">(760) 555-0100</a>',
                '<div class="header-container">',
            ], false);
    }

    public function test_the_top_bar_cant_hold_links_that_run_code(): void
    {
        Theme::current()->update(['top_bar_text' => '[Call](tel:+17605550100) [Click](javascript:alert(1))']);

        $this->get('/about')
            ->assertOk()
            ->assertSee('<a href="tel:+17605550100">Call</a>', false)
            ->assertDontSee('javascript:', false);
    }

    public function test_without_text_there_is_no_top_bar(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertDontSee('class="top-bar', false)
            ->assertDontSee('has-top-bar', false);
    }

    public function test_the_bar_can_fold_away_when_scrolled(): void
    {
        Theme::current()->update(['top_bar_text' => 'Hello']);
        $this->get('/about')->assertDontSee('top-bar-scrolls-away', false);

        Theme::current()->update(['top_bar_stays_visible' => false]);
        $this->get('/about')->assertSee('top-bar-scrolls-away', false);
    }

    public function test_the_text_size_can_be_set_and_is_14px_until_then(): void
    {
        Theme::current()->update(['top_bar_text' => 'Hello']);
        $this->get('/about')->assertSee('--top-bar-font-size: 14px;', false);

        $this->actingAs(User::factory()->create());

        Livewire::test(ThemeSettings::class)
            ->fillForm(['options.top_bar_text_size' => 18])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/about')->assertSee('--top-bar-font-size: 18px;', false);

        Livewire::test(ThemeSettings::class)
            ->fillForm(['options.top_bar_text_size' => 60])
            ->call('save')
            ->assertHasFormErrors(['options.top_bar_text_size']);
    }

    public function test_the_font_follows_the_body_font_until_one_is_chosen_and_is_loaded(): void
    {
        Theme::current()->update(['top_bar_text' => 'Hello', 'font_body' => 'Lato']);
        $this->get('/about')->assertSee("--top-bar-font-family: 'Lato', sans-serif;", false);

        $this->actingAs(User::factory()->create());

        Livewire::test(ThemeSettings::class)
            ->fillForm(['options.top_bar_font' => 'Montserrat'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/about')
            ->assertSee("--top-bar-font-family: 'Montserrat', sans-serif;", false)
            ->assertSee('family=Montserrat:', false);

        Livewire::test(ThemeSettings::class)
            ->fillForm(['options.top_bar_font' => 'Comic Sans MS'])
            ->call('save')
            ->assertHasFormErrors(['options.top_bar_font']);
    }

    public function test_a_top_bar_font_is_only_loaded_when_there_is_a_top_bar(): void
    {
        Theme::current()->update(['options' => ['top_bar_font' => 'Montserrat']]);

        $this->get('/about')->assertDontSee('family=Montserrat:', false);
    }

    public function test_reset_to_defaults_keeps_the_top_bar_text(): void
    {
        $this->actingAs(User::factory()->create());
        Theme::current()->update(['top_bar_text' => 'Call us', 'top_bar_style' => 'primary']);

        Livewire::test(ThemeSettings::class)->callAction('resetToDefaults');

        $theme = Theme::current();
        $this->assertSame('Call us', $theme->top_bar_text);
        $this->assertSame('dark', $theme->top_bar_style->value);
    }

    public function test_social_links_sit_in_the_line_with_the_top_bar_text(): void
    {
        SocialLinkSet::factory()->create(['slug' => 'main']);
        Theme::current()->update(['top_bar_text' => '**Call** us [social:main] today']);

        $this->get('/about')
            ->assertOk()
            ->assertSee('<div class="top-bar-container"><strong>Call</strong> us <span class="social-links social-dark"', false)
            ->assertSee('aria-label="Facebook"', false)
            ->assertDontSee('top-bar-social', false);
    }

    public function test_spread_is_saved_from_the_theme_screen(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ThemeSettings::class)
            ->fillForm(['top_bar_text' => 'Hello', 'top_bar_alignment' => 'spread'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('spread', Theme::current()->top_bar_alignment->value);
    }

    public function test_spread_puts_the_text_at_the_left_and_the_social_links_at_the_right(): void
    {
        SocialLinkSet::factory()->create(['slug' => 'main']);
        Theme::current()->update(['top_bar_text' => '[social:main] Call [(760) 555-0100](tel:+17605550100)', 'top_bar_alignment' => 'spread']);

        $this->get('/about')
            ->assertOk()
            ->assertSee('<div class="top-bar theme-dark align-spread">', false)
            ->assertSee('<div class="top-bar-container"><span class="top-bar-text">Call <a href="tel:+17605550100">(760) 555-0100</a></span><span class="top-bar-social"><span class="social-links social-dark"', false);
    }

    public function test_a_top_bar_shortcode_naming_no_set_is_shown_as_written(): void
    {
        Theme::current()->update(['top_bar_text' => 'Hello [social:missing]']);

        $this->get('/about')
            ->assertOk()
            ->assertSee('<div class="top-bar-container">Hello [social:missing]</div>', false);
    }
}
