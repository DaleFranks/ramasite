<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Image;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SiteFolderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Image::factory()->create(['filename' => 'team.jpg']);
        Theme::current()->update(['logo_path' => 'theme/logo.png']);
        Entry::factory()->published()->create([
            'slug' => 'about',
            'nav_order' => 1,
            'body' => "See [our services](/services), or [elsewhere](https://example.org/x).\n\n[image:team.jpg]",
        ]);
    }

    /**
     * A visit to a site in /blog, as Apache passes it on: the site's .htaccess
     * sends it to public/index.php, which gives Laravel /blog/index.php.
     */
    private function visitInFolder(string $path): TestResponse
    {
        return $this->withServerVariables([
            'SCRIPT_NAME' => '/blog/index.php',
            'SCRIPT_FILENAME' => public_path('index.php'),
        ])->get('http://localhost/blog'.$path);
    }

    public function test_a_site_in_a_folder_keeps_every_address_inside_it(): void
    {
        // The share picture's domain comes from the saved address
        config(['app.url' => 'http://localhost/blog']);

        $this->visitInFolder('/about')
            ->assertOk()
            ->assertSee('href="http://localhost/blog/css/site.css', false)
            ->assertSee('href="http://localhost/blog/about"', false)
            ->assertSee('src="/blog/uploads/theme/logo.png"', false)
            ->assertSee('src="/blog/uploads/images/team.jpg"', false)
            ->assertSee('<a href="/blog/services">our services</a>', false)
            ->assertSee('<a href="https://example.org/x">elsewhere</a>', false)
            ->assertSee('<meta property="og:image" content="http://localhost/blog/uploads/images/team.jpg">', false);
    }

    public function test_a_site_in_a_folder_keeps_its_login_cookie_and_robots_txt_to_that_folder(): void
    {
        $response = $this->visitInFolder('/about');
        $this->assertSame('/blog', collect($response->headers->getCookies())->first()?->getPath());

        $this->assertStringContainsString('Disallow: /blog/admin/', $this->visitInFolder('/robots.txt')->getContent());
    }

    public function test_a_site_at_the_root_is_unchanged(): void
    {
        $response = $this->get('/about')
            ->assertOk()
            ->assertSee('src="/uploads/images/team.jpg"', false)
            ->assertSee('<a href="/services">our services</a>', false);

        $this->assertSame('/', collect($response->headers->getCookies())->first()?->getPath());
    }
}
