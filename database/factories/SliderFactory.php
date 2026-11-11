<?php

namespace Database\Factories;

use App\Models\Image;
use App\Models\Slider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Slider>
 */
class SliderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'animation' => 'slide',
            'seconds_per_slide' => null,
        ];
    }

    /**
     * Give the slider the given number of slides, each with its own library image.
     */
    public function withSlides(int $count = 3): static
    {
        return $this->afterCreating(function (Slider $slider) use ($count): void {
            for ($number = 1; $number <= $count; $number++) {
                $slider->slides()->create([
                    'image_id' => Image::factory()->create()->id,
                    'caption' => "Caption {$number}",
                    'sort' => $number,
                ]);
            }
        });
    }
}
