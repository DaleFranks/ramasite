<?php

namespace Tests\Feature;

use App\EntryLayout;
use App\Markdown;
use App\Models\Entry;
use App\Models\Image;
use App\Models\Module;
use App\Models\Slider;
use App\SliderAnimation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SliderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_slider_is_shown_where_its_shortcode_is_written(): void
    {
        $slider = Slider::factory()->withSlides(2)->create(['name' => 'Highlights', 'slug' => 'highlights', 'seconds_per_slide' => 5]);
        [$first, $second] = $slider->slides->load('image')->all();
        Entry::factory()->published()->create(['slug' => 'home', 'body' => "Intro.\n\n[slider:highlights]\n\nOutro."]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder([
                '<p>Intro.</p>',
                '<div',
                'class="slider animation-slide"',
                'data-seconds="5"',
                'aria-label="Highlights"',
                'src="'.$first->image->url().'"',
                'alt="'.e($first->image->alt_text).'"',
                '<figcaption class="slide-caption">Caption 1</figcaption>',
                'src="'.$second->image->url().'"',
                'loading="lazy"',
                'class="slider-prev"',
                'js/slider.js',
                '<p>Outro.</p>',
            ], false);
    }

    public function test_slides_are_shown_in_their_saved_order(): void
    {
        $slider = Slider::factory()->create(['slug' => 'ordered']);
        $slider->slides()->create(['image_id' => Image::factory()->create(['filename' => 'second.jpg'])->id, 'sort' => 2]);
        $slider->slides()->create(['image_id' => Image::factory()->create(['filename' => 'first.jpg'])->id, 'sort' => 1]);

        $this->assertLessThan(strpos($slider->html(), 'second.jpg'), strpos($slider->html(), 'first.jpg'));
    }

    public function test_the_fade_animation_and_no_timer_are_marked_on_the_slider(): void
    {
        $slider = Slider::factory()->withSlides(2)->create(['animation' => SliderAnimation::Fade]);

        $html = $slider->html();

        $this->assertStringContainsString('class="slider animation-fade"', $html);
        $this->assertStringNotContainsString('data-seconds', $html);
    }

    public function test_an_edge_to_edge_slider_is_marked_and_sits_directly_in_the_page(): void
    {
        Slider::factory()->withSlides(1)->create(['slug' => 'hero', 'edge_to_edge' => true]);
        Entry::factory()->published()->create(['slug' => 'home', 'layout' => EntryLayout::Landing, 'body' => '[slider:hero]']);

        $this->assertMatchesRegularExpression(
            '/<article class="prose">\s*<div\s+class="slider animation-slide edge-to-edge"/',
            $this->get('/')->assertOk()->assertSee('layout-landing')->getContent(),
        );
    }

    public function test_an_edge_to_edge_slider_warns_about_narrow_images_by_name(): void
    {
        $slider = $this->sliderWithImages(['edge_to_edge' => true], [
            ['filename' => 'wide.jpg', 'width' => 1920, 'height' => 800],
            ['filename' => 'narrow.jpg', 'width' => 1200, 'height' => 500],
        ]);

        $warnings = $slider->edgeToEdgeImageWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('narrow.jpg (1200 wide)', $warnings[0]);
        $this->assertStringNotContainsString('wide.jpg (1920', $warnings[0]);
    }

    public function test_an_edge_to_edge_slider_warns_when_slides_differ_in_shape(): void
    {
        $slider = $this->sliderWithImages(['edge_to_edge' => true], [
            ['width' => 1920, 'height' => 800],
            ['width' => 2400, 'height' => 1600],
        ]);

        $warnings = $slider->edgeToEdgeImageWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('same shape', $warnings[0]);
    }

    public function test_an_edge_to_edge_slider_with_suitable_images_has_no_warnings(): void
    {
        $slider = $this->sliderWithImages(['edge_to_edge' => true], [
            ['width' => 1920, 'height' => 800],
            ['width' => 2400, 'height' => 1000],
        ]);

        $this->assertSame([], $slider->edgeToEdgeImageWarnings());
    }

    public function test_a_slider_that_is_not_edge_to_edge_has_no_warnings(): void
    {
        $slider = $this->sliderWithImages([], [
            ['width' => 400, 'height' => 800],
            ['width' => 800, 'height' => 400],
        ]);

        $this->assertSame([], $slider->edgeToEdgeImageWarnings());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $images
     */
    private function sliderWithImages(array $attributes, array $images): Slider
    {
        $slider = Slider::factory()->create($attributes);

        foreach ($images as $sort => $image) {
            $slider->slides()->create(['image_id' => Image::factory()->create($image)->id, 'sort' => $sort]);
        }

        return $slider;
    }

    public function test_a_single_slide_has_no_buttons_or_script(): void
    {
        $html = Slider::factory()->withSlides(1)->create()->html();

        $this->assertStringNotContainsString('slider-prev', $html);
        $this->assertStringNotContainsString('slider.js', $html);
    }

    public function test_shortcodes_for_missing_or_empty_sliders_are_left_as_written(): void
    {
        Slider::factory()->create(['slug' => 'empty']);

        $html = Markdown::toHtml("[slider:empty]\n\n[slider:nope]", withBlocks: true);

        $this->assertStringContainsString('<p>[slider:empty]</p>', $html);
        $this->assertStringContainsString('<p>[slider:nope]</p>', $html);
    }

    public function test_sliders_cannot_be_placed_inside_modules(): void
    {
        Slider::factory()->withSlides(2)->create(['slug' => 'inner']);
        $module = Module::factory()->create(['body' => '[slider:inner]']);

        $this->assertStringContainsString('[slider:inner]', $module->bodyHtml());
    }

    public function test_an_image_in_a_slider_counts_as_in_use(): void
    {
        $slider = Slider::factory()->withSlides(1)->create(['name' => 'Highlights']);
        $image = $slider->slides()->first()->image;

        $this->assertSame(['Slider: Highlights'], $image->usages()->all());

        $image->delete();

        $this->assertModelExists($image);
    }

    public function test_deleting_a_slider_removes_its_slides_but_keeps_the_images(): void
    {
        $slider = Slider::factory()->withSlides(2)->create();
        $images = $slider->slides->pluck('image_id');

        $slider->delete();

        $this->assertDatabaseCount('slides', 0);
        $this->assertSame(2, Image::query()->whereKey($images)->count());
    }
}
