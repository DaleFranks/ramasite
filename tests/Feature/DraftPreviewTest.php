<?php

namespace Tests\Feature;

use App\EntryStatus;
use App\Filament\Resources\Entries\Pages\EditEntry;
use App\Filament\Resources\Entries\Pages\ListEntries;
use App\Models\Entry;
use App\Models\Redirect;
use App\Models\Theme;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DraftPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_admin_sees_a_draft_page_with_a_preview_notice(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Entry::factory()->create(['slug' => 'plans', 'title' => 'Our plans', 'status' => EntryStatus::Draft]);

        $this->get('/plans')
            ->assertOk()
            ->assertSee('Our plans')
            ->assertSee('This page is a draft, so visitors can\'t see it.')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee(route('filament.admin.resources.entries.edit', $page), false);
    }

    public function test_visitors_still_cannot_see_a_draft(): void
    {
        Entry::factory()->create(['slug' => 'plans', 'status' => EntryStatus::Draft]);

        $this->get('/plans')->assertNotFound();
    }

    public function test_a_scheduled_entry_says_when_it_will_appear(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-06-01 12:00:00');
        Entry::factory()->published()->create(['slug' => 'launch', 'published_at' => '2030-03-04 09:30:00']);

        $this->get('/launch')
            ->assertOk()
            ->assertSee('This page will appear on the site on March 4, 2030 at 9:30 am.');
    }

    public function test_a_scheduled_entry_says_when_it_will_appear_in_the_site_timezone(): void
    {
        $this->actingAs(User::factory()->create());
        Theme::current()->update(['timezone' => 'America/Los_Angeles']);
        $this->travelTo('2026-06-01 12:00:00');
        Entry::factory()->published()->create(['slug' => 'launch', 'published_at' => '2030-03-04 17:30:00']);

        $this->get('/launch')->assertSee('This page will appear on the site on March 4, 2030 at 9:30 am.');
    }

    public function test_a_post_shows_its_date_in_the_site_timezone(): void
    {
        Theme::current()->update(['timezone' => 'America/Los_Angeles']);
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news']);
        Entry::factory()->post($blog)->published()->create(['slug' => 'late', 'published_at' => '2026-03-05 03:00:00']);

        $this->get('/news/late')->assertSee('March 4, 2026');
        $this->get('/news')->assertSee('March 4, 2026');
    }

    public function test_a_draft_home_page_can_be_previewed(): void
    {
        Entry::factory()->create(['slug' => 'home', 'title' => 'Welcome', 'status' => EntryStatus::Draft]);

        $this->get('/')->assertNotFound();

        $this->actingAs(User::factory()->create());

        $this->get('/')
            ->assertOk()
            ->assertSee('Welcome')
            ->assertSee('This page is a draft');
    }

    public function test_a_draft_post_and_a_post_in_a_draft_blog_can_be_previewed(): void
    {
        $this->actingAs(User::factory()->create());
        $draftBlog = Entry::factory()->blog()->create(['slug' => 'news', 'status' => EntryStatus::Draft]);
        Entry::factory()->post($draftBlog)->published()->create(['slug' => 'launch']);
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'articles']);
        Entry::factory()->post($blog)->create(['slug' => 'idea', 'title' => 'An idea']);

        $this->get('/news/launch')
            ->assertOk()
            ->assertSee('This post\'s blog isn\'t on the site yet, so visitors can\'t see the post.');

        $this->get('/articles/idea')
            ->assertOk()
            ->assertSee('This post is a draft');
    }

    public function test_a_draft_blog_preview_lists_only_its_published_posts(): void
    {
        $this->actingAs(User::factory()->create());
        $blog = Entry::factory()->blog()->create(['slug' => 'news', 'status' => EntryStatus::Draft]);
        Entry::factory()->post($blog)->published()->create(['title' => 'Ready post']);
        Entry::factory()->post($blog)->create(['title' => 'Unfinished post']);

        $this->get('/news')
            ->assertOk()
            ->assertSee('This blog is a draft')
            ->assertSee('Ready post')
            ->assertDontSee('Unfinished post');
    }

    public function test_a_published_page_has_no_preview_notice_for_admins(): void
    {
        $this->actingAs(User::factory()->create());
        Entry::factory()->published()->create(['slug' => 'about']);

        $this->get('/about')
            ->assertOk()
            ->assertDontSee('preview-bar')
            ->assertDontSee('noindex');
    }

    public function test_a_redirect_from_a_drafts_address_still_works_for_visitors(): void
    {
        Entry::factory()->create(['slug' => 'plans', 'status' => EntryStatus::Draft]);
        Redirect::create(['from_path' => 'plans', 'to' => '/about']);

        $this->get('/plans')->assertRedirect('/about');
    }

    public function test_the_edit_screen_offers_preview_for_a_draft_and_view_once_published(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Entry::factory()->create(['slug' => 'plans', 'status' => EntryStatus::Draft]);

        Livewire::test(EditEntry::class, ['record' => $page->getRouteKey()])
            ->assertActionHasLabel('viewOnSite', 'Preview')
            ->assertActionHasUrl('viewOnSite', url('/plans'))
            ->assertActionShouldOpenUrlInNewTab('viewOnSite');

        $page->update(['status' => EntryStatus::Published]);

        Livewire::test(EditEntry::class, ['record' => $page->getRouteKey()])
            ->assertActionHasLabel('viewOnSite', 'View');
    }

    public function test_the_list_has_a_view_button_for_each_entry(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Entry::factory()->create(['slug' => 'plans', 'status' => EntryStatus::Draft]);

        Livewire::test(ListEntries::class)
            ->assertActionVisible(TestAction::make('viewOnSite')->table($page))
            ->assertActionHasUrl(TestAction::make('viewOnSite')->table($page), url('/plans'));
    }
}
