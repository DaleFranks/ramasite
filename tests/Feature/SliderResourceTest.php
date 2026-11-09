<?php

namespace Tests\Feature;

use App\Filament\Resources\Sliders\Pages\CreateSlider;
use App\Filament\Resources\Sliders\Pages\EditSlider;
use App\Filament\Resources\Sliders\Pages\ListSliders;
use App\Models\Image;
use App\Models\Slider;
use App\Models\User;
use App\SlideCaptionPosition;
use App\SliderAnimation;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SliderResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        auth()->logout();

        $this->get('/admin/sliders')->assertRedirect('/admin/login');
    }

    public function test_the_list_shows_sliders(): void
    {
        $sliders = Slider::factory()->count(2)->withSlides(1)->create();

        Livewire::test(ListSliders::class)->assertCanSeeTableRecords($sliders);
    }

    public function test_a_slider_is_created_with_slides_picked_from_the_library(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $first = Image::factory()->create();
        $second = Image::factory()->create();

        Livewire::test(CreateSlider::class)
            ->fillForm([
                'name' => 'Home Page Highlights',
                'animation' => SliderAnimation::Fade,
                'seconds_per_slide' => 6,
                'slides' => [
                    ['image_id' => $first->id, 'caption' => 'Our team'],
                    ['image_id' => $second->id, 'caption' => null],
                ],
            ])
            ->assertSchemaStateSet(['slug' => 'home-page-highlights'])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $slider = Slider::query()->where('slug', 'home-page-highlights')->sole();
        $this->assertSame(SliderAnimation::Fade, $slider->animation);
        $this->assertSame(6, $slider->seconds_per_slide);
        $this->assertSame([$first->id, $second->id], $slider->slides->pluck('image_id')->all());
        $this->assertSame('Our team', $slider->slides->first()->caption);
    }

    public function test_a_slide_saves_its_text_position_and_colors(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $slider = Slider::factory()->create();

        Livewire::test(EditSlider::class, ['record' => $slider->getRouteKey()])
            ->fillForm(['slides' => [[
                'image_id' => Image::factory()->create()->id,
                'caption' => "Line one\nLine two",
                'caption_position' => SlideCaptionPosition::Left->value,
                'caption_text_color' => '#112233',
                'caption_background_color' => 'rgba(10, 20, 30, 0.4)',
            ]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $slide = $slider->slides()->sole();
        $this->assertSame("Line one\nLine two", $slide->caption);
        $this->assertSame(SlideCaptionPosition::Left, $slide->caption_position);
        $this->assertSame('#112233', $slide->caption_text_color);
        $this->assertSame('rgba(10, 20, 30, 0.4)', $slide->caption_background_color);
    }

    public function test_a_slide_rejects_colors_that_are_not_colors(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $slider = Slider::factory()->create();

        Livewire::test(EditSlider::class, ['record' => $slider->getRouteKey()])
            ->fillForm(['slides' => [[
                'image_id' => Image::factory()->create()->id,
                'caption_text_color' => 'red; position: fixed',
                'caption_background_color' => 'url(x)',
            ]]])
            ->call('save')
            ->assertHasFormErrors(['slides.0.caption_text_color', 'slides.0.caption_background_color']);

        $undoRepeaterFake();
    }

    public function test_the_edge_to_edge_help_only_shows_once_it_is_selected(): void
    {
        Livewire::test(CreateSlider::class)
            ->assertDontSee('1920 pixels wide')
            ->fillForm(['edge_to_edge' => true])
            ->assertSee('1920 pixels wide');
    }

    public function test_saving_an_edge_to_edge_slider_with_a_narrow_image_saves_and_warns(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $narrow = Image::factory()->create(['filename' => 'narrow.jpg', 'width' => 1000, 'height' => 500]);
        $slider = Slider::factory()->create();

        Livewire::test(EditSlider::class, ['record' => $slider->getRouteKey()])
            ->fillForm([
                'edge_to_edge' => true,
                'slides' => [['image_id' => $narrow->id, 'caption' => null]],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Check this slider\'s images');

        $undoRepeaterFake();

        $this->assertTrue($slider->refresh()->edge_to_edge);
    }

    public function test_saving_a_slider_with_a_narrow_image_gives_no_warning_unless_edge_to_edge(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $narrow = Image::factory()->create(['width' => 1000, 'height' => 500]);
        $slider = Slider::factory()->create();

        Livewire::test(EditSlider::class, ['record' => $slider->getRouteKey()])
            ->fillForm(['slides' => [['image_id' => $narrow->id, 'caption' => null]]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified('Check this slider\'s images');

        $undoRepeaterFake();
    }

    public function test_a_slider_needs_at_least_one_slide(): void
    {
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(CreateSlider::class)
            ->fillForm(['name' => 'Empty', 'slides' => []])
            ->call('create')
            ->assertHasFormErrors(['slides']);

        $undoRepeaterFake();
    }

    public function test_slides_can_be_reordered(): void
    {
        $undoRepeaterFake = Repeater::fake();
        $slider = Slider::factory()->withSlides(2)->create();
        [$first, $second] = $slider->slides->pluck('image_id')->all();

        Livewire::test(EditSlider::class, ['record' => $slider->getRouteKey()])
            ->fillForm(['slides' => [
                ['image_id' => $second, 'caption' => null],
                ['image_id' => $first, 'caption' => null],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $this->assertSame([$second, $first], $slider->refresh()->slides->pluck('image_id')->all());
    }
}
