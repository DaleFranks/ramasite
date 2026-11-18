<?php

namespace Database\Factories;

use App\Models\SocialLinkSet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SocialLinkSet>
 */
class SocialLinkSetFactory extends Factory
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
            'logo_style' => 'dark',
            'logo_size' => 32,
            'opens_in_new_tab' => true,
            'links' => [
                ['service' => 'facebook', 'url' => 'https://www.facebook.com/example'],
                ['service' => 'instagram', 'url' => 'https://www.instagram.com/example'],
            ],
        ];
    }
}
