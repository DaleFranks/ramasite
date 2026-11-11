<?php

namespace Tests\Feature;

use App\BandEdge;
use App\BoxStyle;
use App\Models\Entry;
use App\Models\Image;
use App\Models\Module;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModuleShortcodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_module_is_shown_where_its_shortcode_is_written(): void
    {
        Module::factory()->create(['slug' => 'cta', 'body' => "## Ready?\n\nGet in **touch**.", 'style' => BoxStyle::Dark]);
        $entry = Entry::factory()->published()->create(['body' => "Before.\n\n[module:cta]\n\nAfter."]);

        $html = $entry->bodyHtml();

        $this->assertStringContainsString(
            "<p>Before.</p>\n<div class=\"module cta-module theme-dark\">\n<h2>Ready?</h2>\n<p>Get in <strong>touch</strong>.</p>\n\n</div>\n<p>After.</p>",
            $html,
        );
    }

    public function test_a_module_shows_its_background_image(): void
    {
        $image = Image::factory()->create(['filename' => 'sky.jpg']);
        $module = Module::factory()->create(['slug' => 'band', 'body' => 'Hello.', 'style' => BoxStyle::Dark, 'background_image_id' => $image->id]);

        $this->assertStringStartsWith(
            '<div class="module band-module theme-dark has-background-image" style="'.e("background-image: url('{$image->url()}')").'">',
            $module->html(),
        );
    }

    public function test_a_module_is_marked_with_its_edge_shapes(): void
    {
        $module = Module::factory()->create(['slug' => 'band', 'body' => 'Hello.', 'top_edge' => BandEdge::SlantLeft, 'bottom_edge' => BandEdge::Wave]);

        $this->assertStringStartsWith(
            '<div class="module band-module has-top-edge edge-top-slant-left has-bottom-edge edge-bottom-wave">',
            $module->html(),
        );
    }

    public function test_a_module_with_only_one_edge_shape_is_only_marked_on_that_side(): void
    {
        $module = Module::factory()->create(['slug' => 'band', 'body' => 'Hello.', 'bottom_edge' => BandEdge::SlantRight]);

        $this->assertStringStartsWith('<div class="module band-module has-bottom-edge edge-bottom-slant-right">', $module->html());
    }

    public function test_an_image_behind_a_module_counts_as_in_use(): void
    {
        $image = Image::factory()->create();
        Module::factory()->create(['name' => 'Hero band', 'background_image_id' => $image->id]);

        $this->assertSame(['Module: Hero band'], $image->usages()->all());

        $image->delete();

        $this->assertModelExists($image);
    }

    public function test_a_module_without_a_style_has_no_theme_class(): void
    {
        Module::factory()->create(['slug' => 'note', 'body' => 'Hello.']);
        $entry = Entry::factory()->published()->create(['body' => '[module:note]']);

        $this->assertStringContainsString('<div class="module note-module">', $entry->bodyHtml());
    }

    public function test_the_shortcode_ignores_case_and_an_old_style_md_extension(): void
    {
        Module::factory()->create(['slug' => 'cta', 'body' => 'Hello.']);
        $entry = Entry::factory()->published()->create(['body' => "[Module:CTA.md]\n\n[module: cta ]"]);

        $this->assertSame(2, substr_count($entry->bodyHtml(), '<div class="module cta-module">'));
    }

    public function test_a_shortcode_for_a_missing_module_is_left_as_written(): void
    {
        $entry = Entry::factory()->published()->create(['body' => '[module:nothing-here]']);

        $this->assertStringContainsString('[module:nothing-here]', $entry->bodyHtml());
    }

    public function test_modules_inside_modules_are_not_expanded(): void
    {
        Module::factory()->create(['slug' => 'outer', 'body' => '[module:inner]']);
        Module::factory()->create(['slug' => 'inner', 'body' => 'Inner text.']);
        $entry = Entry::factory()->published()->create(['body' => '[module:outer]']);

        $html = $entry->bodyHtml();

        $this->assertStringContainsString('[module:inner]', $html);
        $this->assertStringNotContainsString('Inner text.', $html);
    }

    public function test_a_module_on_a_page_can_use_navigation_and_the_site_name(): void
    {
        Entry::factory()->published()->create(['slug' => 'about', 'title' => 'About', 'nav_order' => 1]);
        Module::factory()->create(['slug' => 'links', 'body' => "[navigation:flat]\n\n[site-name]"]);
        Entry::factory()->published()->create(['slug' => 'home', 'body' => '[module:links]']);

        $this->get('/')
            ->assertOk()
            ->assertSee('<div class="module links-module">', false)
            ->assertSee('<a href="'.url('about').'">About</a>', false);
    }

    public function test_the_database_refuses_to_delete_an_image_a_module_uses_as_its_background(): void
    {
        $image = Image::factory()->create();
        Module::factory()->create(['background_image_id' => $image->id]);

        $this->expectException(QueryException::class);

        DB::table('images')->where('id', $image->id)->delete();
    }

    public function test_the_constraint_update_clears_backgrounds_pointing_to_missing_images(): void
    {
        $migration = 'database/migrations/2026_11_11_070355_add_background_image_constraint_to_modules_table.php';
        Artisan::call('migrate:rollback', ['--path' => $migration]);
        $module = Module::factory()->create(['background_image_id' => 999]);

        Artisan::call('migrate', ['--path' => $migration]);

        $this->assertNull($module->refresh()->background_image_id);
    }
}
