<?php

namespace Tests\Feature;

use App\EntryStatus;
use App\Models\Entry;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_names_its_own_address_without_the_query_string(): void
    {
        config(['app.url' => 'https://example.com']);
        Entry::factory()->published()->create(['slug' => 'about']);

        $this->get('https://example.com/about?utm_source=newsletter')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://example.com/about">', false)
            ->assertSee('<meta property="og:url" content="https://example.com/about">', false);
    }

    public function test_a_visit_to_www_names_the_main_domain(): void
    {
        config(['app.url' => 'https://example.com']);
        Entry::factory()->published()->create(['slug' => 'about']);

        $this->get('https://www.example.com/about')
            ->assertSee('<link rel="canonical" href="https://example.com/about">', false);
    }

    public function test_a_visit_to_www_shares_its_picture_from_the_main_domain(): void
    {
        config(['app.url' => 'https://example.com']);
        Image::factory()->create(['filename' => 'team.jpg']);
        Entry::factory()->published()->create(['slug' => 'about', 'body' => '[image:team.jpg]']);

        $this->get('https://www.example.com/about')
            ->assertSee('<meta property="og:image" content="https://example.com/uploads/images/team.jpg">', false);
    }

    public function test_a_site_saved_with_www_names_the_www_address(): void
    {
        config(['app.url' => 'https://www.example.com']);
        Entry::factory()->published()->create(['slug' => 'about']);

        $this->get('https://example.com/about')
            ->assertSee('<link rel="canonical" href="https://www.example.com/about">', false);
    }

    public function test_a_visit_to_another_domain_names_that_domain(): void
    {
        config(['app.url' => 'https://old-name.com']);
        Entry::factory()->published()->create(['slug' => 'about']);

        $this->get('https://new-name.com/about')
            ->assertSee('<link rel="canonical" href="https://new-name.com/about">', false);
    }

    public function test_the_home_page_names_the_sites_main_address(): void
    {
        config(['app.url' => 'https://example.com']);
        Entry::factory()->published()->create(['slug' => 'home']);

        $this->get('https://www.example.com/')
            ->assertSee('<link rel="canonical" href="https://example.com/">', false);
    }

    public function test_a_post_names_its_address_under_its_blog(): void
    {
        config(['app.url' => 'https://example.com']);
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news']);
        Entry::factory()->post($blog)->published()->create(['slug' => 'launch']);

        $this->get('https://example.com/news/launch')
            ->assertSee('<link rel="canonical" href="https://example.com/news/launch">', false);
    }

    public function test_a_blogs_later_pages_keep_their_page_number(): void
    {
        config(['app.url' => 'https://example.com']);
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news', 'posts_per_page' => 1]);
        Entry::factory()->post($blog)->published()->count(2)->create();

        $this->get('https://example.com/news')
            ->assertSee('<link rel="canonical" href="https://example.com/news">', false);
        $this->get('https://example.com/news?page=2')
            ->assertSee('<link rel="canonical" href="https://example.com/news?page=2">', false);
    }

    public function test_previews_and_the_not_found_page_name_no_address(): void
    {
        $this->actingAs(User::factory()->create());
        Entry::factory()->create(['slug' => 'plans', 'status' => EntryStatus::Draft]);

        $this->get('/plans')->assertOk()->assertDontSee('rel="canonical"', false);
        $this->get('/nothing-here')->assertNotFound()->assertDontSee('rel="canonical"', false);
    }

    public function test_the_sitemap_and_feed_use_the_main_domain(): void
    {
        config(['app.url' => 'https://example.com']);
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news']);
        Entry::factory()->post($blog)->published()->create(['slug' => 'launch']);

        $this->get('https://www.example.com/sitemap.xml')
            ->assertSee('<loc>https://example.com/news/launch</loc>', false)
            ->assertDontSee('www.example.com', false);

        $this->get('https://www.example.com/news/feed.xml')
            ->assertSee('<link>https://example.com/news/launch</link>', false)
            ->assertSee('<atom:link href="https://example.com/news/feed.xml"', false);
    }
}
