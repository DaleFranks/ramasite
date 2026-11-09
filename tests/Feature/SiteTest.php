<?php

namespace Tests\Feature;

use App\BlogColumns;
use App\EntryStatus;
use App\Models\Entry;
use App\Models\Image;
use App\Models\Theme;
use Database\Seeders\HomePageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_is_shown_at_the_root_address(): void
    {
        Entry::factory()->published()->create(['slug' => 'home', 'title' => 'Hello there']);

        $this->get('/')
            ->assertOk()
            ->assertSee('<h1 class="page-title">Hello there</h1>', false);
    }

    public function test_the_home_slug_redirects_to_the_root_address(): void
    {
        Entry::factory()->published()->create(['slug' => 'home']);

        $this->get('/home')->assertRedirect('/')->assertStatus(301);
    }

    public function test_a_page_is_shown_at_its_path_with_its_markdown_rendered(): void
    {
        Entry::factory()->published()->create([
            'slug' => 'services/design',
            'title' => 'Design',
            'body' => "# Design\n\nWe make **bold** things.",
            'meta_description' => 'Design services.',
        ]);

        $this->get('/services/design')
            ->assertOk()
            ->assertSee('<title>Design | ', false)
            ->assertSee('<meta name="description" content="Design services.">', false)
            ->assertSee('<strong>bold</strong>', false)
            ->assertDontSee('<h1>Design</h1>', false);
    }

    public function test_drafts_and_entries_scheduled_for_later_are_not_shown(): void
    {
        Entry::factory()->create(['slug' => 'draft', 'status' => EntryStatus::Draft]);
        Entry::factory()->published()->create(['slug' => 'later', 'published_at' => now()->addDay()]);

        $this->get('/draft')->assertNotFound();
        $this->get('/later')->assertNotFound();
    }

    public function test_an_unknown_address_shows_the_sites_not_found_page(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('class="site-header"', false);
    }

    public function test_a_blog_lists_its_published_posts_newest_first(): void
    {
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news']);
        Entry::factory()->post($blog)->published()->create(['title' => 'Older post', 'published_at' => now()->subWeek()]);
        Entry::factory()->post($blog)->published()->create(['title' => 'Newer post', 'published_at' => now()->subDay()]);
        Entry::factory()->post($blog)->create(['title' => 'Draft post']);
        Entry::factory()->post()->published()->create(['title' => 'Post in another blog']);

        $this->get('/news')
            ->assertOk()
            ->assertSeeInOrder(['Newer post', 'Older post'])
            ->assertDontSee('Draft post')
            ->assertDontSee('Post in another blog');
    }

    public function test_a_blog_feed_shows_the_first_three_sentences_of_each_post(): void
    {
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news']);
        Entry::factory()->post($blog)->published()->create([
            'body' => "# Title\n\nOne. Two? Three! Four.",
        ]);

        $this->get('/news')
            ->assertOk()
            ->assertSee('One. Two? Three!')
            ->assertDontSee('Four.');
    }

    public function test_a_blog_lists_as_many_posts_per_page_as_it_is_set_to(): void
    {
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news', 'posts_per_page' => 2]);

        foreach (['First', 'Second', 'Third'] as $days => $title) {
            Entry::factory()->post($blog)->published()->create(['title' => "{$title} post", 'published_at' => now()->subDays($days + 1)]);
        }

        $this->get('/news')
            ->assertOk()
            ->assertSeeInOrder(['First post', 'Second post'])
            ->assertDontSee('Third post')
            ->assertSee('Page 1 of 2');

        $this->get('/news?page=2')->assertOk()->assertSee('Third post');
    }

    public function test_a_blog_lets_the_window_decide_its_columns_by_default(): void
    {
        Entry::factory()->blog()->published()->create(['slug' => 'news']);

        $this->get('/news')
            ->assertOk()
            ->assertSee('<div class="card-grid blog-feed">', false);
    }

    /**
     * @return array<string, array{BlogColumns, string}>
     */
    public static function fixedBlogColumns(): array
    {
        return [
            'one column' => [BlogColumns::One, 'columns-1'],
            'two columns' => [BlogColumns::Two, 'columns-2'],
            'three columns' => [BlogColumns::Three, 'columns-3'],
        ];
    }

    #[DataProvider('fixedBlogColumns')]
    public function test_a_blog_uses_the_number_of_columns_it_is_set_to(BlogColumns $columns, string $expectedClass): void
    {
        Entry::factory()->blog()->published()->create(['slug' => 'news', 'posts_columns' => $columns]);

        $this->get('/news')
            ->assertOk()
            ->assertSee('<div class="card-grid blog-feed '.$expectedClass.'">', false);
    }

    public function test_a_blog_feed_shows_each_posts_first_image(): void
    {
        Image::factory()->create(['filename' => 'first.jpg']);
        Image::factory()->create(['filename' => 'second.jpg']);
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news']);
        Entry::factory()->post($blog)->published()->create([
            'body' => "Intro.\n\n[image:First.jpg|400|left|A picture]\n\n![Another](second.jpg)",
        ]);
        Entry::factory()->post($blog)->published()->create([
            'body' => 'Markdown first. ![Remote](https://example.com/photo.png)',
        ]);
        Entry::factory()->post($blog)->published()->create(['body' => 'No pictures here.']);
        Entry::factory()->post($blog)->published()->create(['body' => '[image:not-in-library.jpg]']);

        $response = $this->get('/news')->assertOk();

        $response->assertSee('<img src="/uploads/images/first.jpg" alt="" loading="lazy">', false)
            ->assertSee('<img src="https://example.com/photo.png" alt="" loading="lazy">', false)
            ->assertDontSee('second.jpg')
            ->assertDontSee('not-in-library.jpg');
        $this->assertSame(2, substr_count($response->getContent(), 'class="feed-image"'));
    }

    public function test_a_post_is_shown_with_a_link_back_to_its_blog(): void
    {
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news', 'title' => 'News']);
        Entry::factory()->post($blog)->published()->create(['slug' => 'launch', 'title' => 'We launched']);

        $this->get('/news/launch')
            ->assertOk()
            ->assertSee('We launched')
            ->assertSee('Back to News');
    }

    public function test_a_post_in_an_unpublished_blog_is_not_shown(): void
    {
        $blog = Entry::factory()->blog()->create(['slug' => 'news', 'status' => EntryStatus::Draft]);
        Entry::factory()->post($blog)->published()->create(['slug' => 'launch']);

        $this->get('/news/launch')->assertNotFound();
    }

    public function test_a_post_in_a_blog_scheduled_for_later_is_not_shown_until_then(): void
    {
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news', 'published_at' => now()->addDay()]);
        Entry::factory()->post($blog)->published()->create(['slug' => 'launch']);

        $this->get('/news/launch')->assertNotFound();

        $this->travel(2)->days();

        $this->get('/news/launch')->assertOk();
    }

    public function test_the_menu_shows_published_entries_in_order_with_children_nested(): void
    {
        Entry::factory()->published()->create(['slug' => 'home', 'title' => 'Home', 'nav_order' => 1]);
        $services = Entry::factory()->published()->create(['slug' => 'services', 'title' => 'Services', 'nav_order' => 3]);
        Entry::factory()->published()->create(['slug' => 'about', 'title' => 'About', 'nav_order' => 2]);
        Entry::factory()->published()->create(['slug' => 'design', 'title' => 'Design', 'nav_order' => 1, 'parent_id' => $services->id]);
        Entry::factory()->create(['slug' => 'secret', 'title' => 'Secret draft', 'nav_order' => 4]);
        Entry::factory()->published()->create(['slug' => 'hidden', 'title' => 'Not in menu']);

        $this->get('/design')
            ->assertOk()
            ->assertSeeInOrder(['Home', 'About', 'Services', 'nav-submenu', 'Design'], false)
            ->assertSee('<li class="has-children active-parent">', false)
            ->assertDontSee('Secret draft')
            ->assertDontSee('Not in menu');
    }

    public function test_the_theme_settings_are_applied_to_the_page(): void
    {
        Entry::factory()->published()->create(['slug' => 'home']);
        Theme::current()->update([
            'site_name' => 'Acme Studio',
            'color_primary' => '#ff0000',
            'font_body' => 'Lato',
            'sticky_header' => false,
        ]);

        $this->get('/')
            ->assertSee('<span class="logo-text">Acme Studio</span>', false)
            ->assertSee('--color-primary: #ff0000;', false)
            ->assertSee("--font-family-body: 'Lato', sans-serif;", false)
            ->assertSee('family=Lato:', false)
            ->assertSee('<body class="logo-left header-not-sticky layout-standard">', false);
    }

    public function test_custom_css_cannot_close_the_style_element(): void
    {
        Entry::factory()->published()->create(['slug' => 'home']);
        Theme::current()->update(['custom_css' => 'p { color: red; }</style><script>alert(1)</script>']);

        $this->get('/')
            ->assertSee('p { color: red; }', false)
            ->assertDontSee('<script>alert(1)', false);
    }

    public function test_script_tags_and_javascript_links_in_content_are_not_run(): void
    {
        Entry::factory()->published()->create([
            'slug' => 'home',
            'body' => "<script>alert(1)</script>\n\n[Click](javascript:alert(1))",
        ]);

        $this->get('/')
            ->assertDontSee('<script>alert(1)', false)
            ->assertDontSee('href="javascript:', false);
    }

    public function test_the_home_page_seeder_creates_a_published_home_page_once(): void
    {
        $this->seed(HomePageSeeder::class);
        Entry::query()->where('path', 'home')->update(['title' => 'My own home page']);

        $this->seed(HomePageSeeder::class);

        $this->assertSame('My own home page', Entry::query()->where('path', 'home')->sole()->title);
        $this->get('/')->assertOk()->assertSee('My own home page');
    }
}
