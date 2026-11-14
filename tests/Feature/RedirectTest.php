<?php

namespace Tests\Feature;

use App\EntryType;
use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Models\Entry;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_old_address_is_sent_to_its_new_one_permanently_and_counted(): void
    {
        $redirect = Redirect::factory()->create(['from_path' => 'webmaster.aspx', 'to' => '/services/webmaster']);

        $this->get('/webmaster.aspx')->assertRedirect(url('/services/webmaster'))->assertStatus(301);

        $redirect->refresh();
        $this->assertSame(1, $redirect->hits);
        $this->assertNotNull($redirect->last_hit_at);
    }

    public function test_every_use_is_counted_even_by_copies_loaded_at_the_same_time(): void
    {
        Redirect::factory()->create(['from_path' => 'old', 'to' => '/new', 'hits' => 5]);
        $first = Redirect::findFor('old');
        $second = Redirect::findFor('old');

        $first->follow(request());
        $second->follow(request());

        $this->assertSame(7, Redirect::query()->sole()->hits);
    }

    public function test_matching_ignores_capitals_and_slashes_and_keeps_the_query_string(): void
    {
        Redirect::factory()->create(['from_path' => '/About_Us.aspx/', 'to' => '/services']);

        $this->get('/about_us.ASPX?ref=old')->assertRedirect(url('/services?ref=old'));
    }

    public function test_a_temporary_redirect_and_a_full_web_address(): void
    {
        Redirect::factory()->create(['from_path' => 'shop', 'to' => 'https://shop.example.com', 'status_code' => 302]);

        $this->get('/shop')->assertRedirect('https://shop.example.com')->assertStatus(302);
    }

    public function test_a_wildcard_covers_everything_under_it_and_carries_the_rest_across(): void
    {
        Redirect::factory()->create(['from_path' => 'img/*', 'to' => '/uploads/images/*']);
        Redirect::factory()->create(['from_path' => 'img/logos/*', 'to' => '/uploads/theme/*']);

        $this->get('/img/seo.jpg')->assertRedirect(url('/uploads/images/seo.jpg'));
        $this->get('/img/logos/webdev.png')->assertRedirect(url('/uploads/theme/webdev.png'));
    }

    public function test_addresses_with_a_colon_still_match(): void
    {
        Redirect::factory()->create(['from_path' => 'shop/*', 'to' => '/store/*']);
        Redirect::factory()->create(['from_path' => 'a:80', 'to' => '/port']);

        $this->get('/shop/item:12')->assertRedirect(url('/store/item:12'));
        $this->get('/a:80')->assertRedirect(url('/port'));
        $this->assertSame('a:80', Redirect::normalizePath('/a:80?x=1'));
        $this->assertSame('old-page', Redirect::normalizePath('https://example.com/Old-Page/'));
    }

    public function test_a_wildcard_carries_the_rest_of_the_address_across_as_it_was_typed(): void
    {
        Redirect::factory()->create(['from_path' => 'docs/*', 'to' => 'https://old.example.com/docs/*']);

        $this->get('/Docs/Price%20List.PDF')->assertRedirect('https://old.example.com/docs/Price%20List.PDF');
    }

    public function test_a_published_page_always_wins_over_a_redirect(): void
    {
        Entry::factory()->published()->create(['slug' => 'services', 'title' => 'Our services']);
        Redirect::factory()->create(['from_path' => 'services', 'to' => '/elsewhere']);

        $this->get('/services')->assertOk()->assertSee('Our services');
    }

    public function test_an_address_with_neither_page_nor_redirect_is_not_found(): void
    {
        $this->get('/nothing-here')->assertNotFound();
    }

    public function test_moving_a_published_page_leaves_a_redirect_and_updates_redirects_that_led_to_it(): void
    {
        $page = Entry::factory()->published()->create(['slug' => 'hosting']);
        Redirect::factory()->create(['from_path' => 'hosting.aspx', 'to' => '/hosting']);

        $page->update(['slug' => 'services/hosting-plans']);

        $this->get('/hosting')->assertRedirect(url('/services/hosting-plans'))->assertStatus(301);
        $this->get('/hosting.aspx')->assertRedirect(url('/services/hosting-plans'));
        $this->assertTrue(Redirect::query()->where('from_path', 'hosting')->sole()->automatic);
    }

    public function test_moving_a_page_back_removes_the_redirect_that_would_shadow_it(): void
    {
        $page = Entry::factory()->published()->create(['slug' => 'first']);
        $page->update(['slug' => 'second']);
        $page->update(['slug' => 'first']);

        $this->assertFalse(Redirect::query()->where('from_path', 'first')->exists());
        $this->get('/second')->assertRedirect(url('/first'));
        $this->get('/first')->assertOk();
    }

    public function test_renaming_a_blog_leaves_redirects_for_its_posts(): void
    {
        $blog = Entry::factory()->published()->create(['type' => EntryType::Blog, 'slug' => 'blog']);
        Entry::factory()->published()->create(['type' => EntryType::Post, 'blog_id' => $blog->id, 'slug' => 'hello']);

        $blog->update(['slug' => 'articles']);

        $this->get('/blog')->assertRedirect(url('/articles'));
        $this->get('/blog/hello')->assertRedirect(url('/articles/hello'));
    }

    public function test_moving_a_draft_leaves_no_redirect(): void
    {
        $draft = Entry::factory()->create(['slug' => 'draft-one']);

        $draft->update(['slug' => 'draft-two']);

        $this->assertSame(0, Redirect::query()->count());
    }

    public function test_redirects_are_added_and_checked_on_the_redirects_screen(): void
    {
        $this->actingAs(User::factory()->create());
        Entry::factory()->published()->create(['slug' => 'services']);

        Livewire::test(ManageRedirects::class)
            ->callAction('create', data: ['from_path' => '/Webmaster.aspx', 'to' => '/services/webmaster', 'status_code' => 301])
            ->assertHasNoActionErrors();

        $this->assertSame('webmaster.aspx', Redirect::query()->sole()->from_path);

        Livewire::test(ManageRedirects::class)
            ->callAction('create', data: ['from_path' => 'services', 'to' => '/elsewhere', 'status_code' => 301])
            ->assertHasActionErrors(['from_path']);

        Livewire::test(ManageRedirects::class)
            ->callAction('create', data: ['from_path' => 'admin/old', 'to' => '/x', 'status_code' => 301])
            ->assertHasActionErrors(['from_path']);

        Livewire::test(ManageRedirects::class)
            ->callAction('create', data: ['from_path' => 'loop', 'to' => '/loop', 'status_code' => 301])
            ->assertHasActionErrors(['to']);

        Livewire::test(ManageRedirects::class)
            ->callAction('create', data: ['from_path' => 'webmaster.aspx', 'to' => '/again', 'status_code' => 301])
            ->assertHasActionErrors(['from_path']);

        Livewire::test(ManageRedirects::class)
            ->callAction('create', data: ['from_path' => 'x', 'to' => 'javascript:alert(1)', 'status_code' => 301])
            ->assertHasActionErrors(['to']);
    }

    public function test_a_redirect_that_would_loop_through_others_is_refused(): void
    {
        $this->actingAs(User::factory()->create());
        Redirect::factory()->create(['from_path' => 'b', 'to' => '/c']);
        Redirect::factory()->create(['from_path' => 'c', 'to' => '/a']);

        Livewire::test(ManageRedirects::class)
            ->callAction('create', data: ['from_path' => 'a', 'to' => '/b', 'status_code' => 301])
            ->assertHasActionErrors(['to']);

        // When a redirect is edited, its saved version isn't part of the trail
        $fromX = Redirect::factory()->create(['from_path' => 'x', 'to' => '/y']);
        Redirect::factory()->create(['from_path' => 'y', 'to' => '/x']);

        Livewire::test(ManageRedirects::class)
            ->callTableAction('edit', $fromX, data: ['from_path' => 'z', 'to' => '/y', 'status_code' => 301])
            ->assertHasNoTableActionErrors();

        // A published page ends the trail, as visits there are never redirected
        Entry::factory()->published()->create(['slug' => 'c']);

        $this->assertFalse(Redirect::wouldLoop('a', '/b'));
        $this->assertFalse(Redirect::wouldLoop('a', 'https://elsewhere.test/b'));
    }

    public function test_the_screen_lists_redirects_with_their_use(): void
    {
        $this->actingAs(User::factory()->create());
        $redirects = Redirect::factory()->count(2)->create(['hits' => 3]);

        Livewire::test(ManageRedirects::class)->assertCanSeeTableRecords($redirects);
    }
}
