<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Module;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_footer_is_created_with_the_default_text_the_first_time_a_page_is_shown(): void
    {
        Theme::factory()->create(['site_name' => 'Acme']);
        Entry::factory()->published()->create(['slug' => 'home', 'title' => 'Home', 'nav_order' => 1]);

        $this->get('/')
            ->assertOk()
            ->assertSee('<nav class="footer-nav-flat" aria-label="Footer menu">', false)
            ->assertSee('© '.now()->year.' Acme. All rights reserved.', false);

        $this->assertSame(Module::FOOTER_DEFAULT_BODY, Module::footer()->body);
    }

    public function test_the_footer_shows_its_edited_markdown(): void
    {
        Entry::factory()->published()->create(['slug' => 'home']);
        Module::factory()->footer()->create(['body' => 'Made with **care**.']);

        $this->get('/')
            ->assertOk()
            ->assertSee('<div class="module footer-module">', false)
            ->assertSee('<p>Made with <strong>care</strong>.</p>', false)
            ->assertDontSee('footer-nav-flat', false);
    }

    public function test_flat_navigation_lists_only_the_top_level_menu_items(): void
    {
        $services = Entry::factory()->published()->create(['slug' => 'services', 'title' => 'Services', 'nav_order' => 2]);
        Entry::factory()->published()->create(['slug' => 'about', 'title' => 'About', 'nav_order' => 1]);
        Entry::factory()->published()->create(['slug' => 'services/design', 'title' => 'Design', 'nav_order' => 1, 'parent_id' => $services->id]);
        Entry::factory()->published()->create(['slug' => 'hidden', 'title' => 'Hidden']);
        $footer = Module::factory()->footer()->create(['body' => '[navigation:flat]']);

        $html = $footer->bodyHtml();

        $this->assertStringContainsString('<a href="'.url('about').'">About</a>', $html);
        $this->assertStringContainsString('<a href="'.url('services').'">Services</a>', $html);
        $this->assertStringNotContainsString('Design', $html);
        $this->assertStringNotContainsString('Hidden', $html);
        $this->assertStringNotContainsString('<p>', $html);
        $this->assertLessThan(strpos($html, 'Services'), strpos($html, 'About'));
    }

    public function test_multi_navigation_shows_a_column_per_top_level_item_with_its_children(): void
    {
        $services = Entry::factory()->published()->create(['slug' => 'services', 'title' => 'Services', 'nav_order' => 1]);
        Entry::factory()->published()->create(['slug' => 'services/design', 'title' => 'Design', 'nav_order' => 1, 'parent_id' => $services->id]);
        $footer = Module::factory()->footer()->create(['body' => '[Navigation:Multi]']);

        $html = $footer->bodyHtml();

        $this->assertStringContainsString('<nav class="footer-nav-multi" aria-label="Footer menu">', $html);
        $this->assertStringContainsString('<h4><a href="'.url('services').'">Services</a></h4>', $html);
        $this->assertStringContainsString('<li><a href="'.url('services/design').'">Design</a></li>', $html);
    }

    public function test_the_site_name_is_escaped(): void
    {
        Theme::factory()->create(['site_name' => '<script>Acme</script>']);
        $footer = Module::factory()->footer()->create(['body' => '[site-name]']);

        $html = $footer->bodyHtml();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;Acme', $html);
    }

    public function test_the_current_year_is_the_sites_own(): void
    {
        Theme::factory()->create(['timezone' => 'America/Los_Angeles']);
        $footer = Module::factory()->footer()->create(['body' => '© [curr-year]']);

        // New Year's Day in UTC, still New Year's Eve in California
        $this->travelTo('2027-01-01 03:00:00');

        $this->assertStringContainsString('© 2026', $footer->bodyHtml());
    }

    public function test_the_footer_cannot_be_deleted(): void
    {
        $footer = Module::factory()->footer()->create();

        $footer->delete();

        $this->assertModelExists($footer);
    }
}
