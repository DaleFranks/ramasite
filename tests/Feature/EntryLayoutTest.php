<?php

namespace Tests\Feature;

use App\EntryLayout;
use App\EntryType;
use App\Filament\Resources\Entries\Pages\CreateEntry;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EntryLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_use_the_standard_layout_unless_told_otherwise(): void
    {
        Entry::factory()->published()->create(['slug' => 'about', 'title' => 'About us']);

        $this->get('/about')
            ->assertOk()
            ->assertSee('layout-standard', false)
            ->assertSee('<h1 class="page-title">About us</h1>', false);
    }

    public function test_the_full_width_layout_keeps_the_title(): void
    {
        Entry::factory()->published()->create(['slug' => 'about', 'title' => 'About us', 'layout' => EntryLayout::FullWidth]);

        $this->get('/about')
            ->assertOk()
            ->assertSee('layout-full-width', false)
            ->assertSee('<h1 class="page-title">About us</h1>', false);
    }

    public function test_the_landing_layout_hides_the_title_on_screen_but_keeps_it_for_search_engines(): void
    {
        Entry::factory()->published()->create(['slug' => 'home', 'title' => 'Welcome', 'layout' => EntryLayout::Landing]);

        $this->get('/')
            ->assertOk()
            ->assertSee('layout-landing', false)
            ->assertSee('<h1 class="page-title visually-hidden">Welcome</h1>', false);
    }

    public function test_a_landing_page_can_show_its_title(): void
    {
        Entry::factory()->published()->create(['slug' => 'home', 'title' => 'Welcome', 'layout' => EntryLayout::Landing, 'show_title' => true]);

        $this->get('/')->assertSee('<h1 class="page-title">Welcome</h1>', false);
    }

    public function test_the_show_title_switch_is_offered_only_for_the_landing_layout(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->assertFormFieldHidden('show_title')
            ->fillForm(['title' => 'Launch', 'layout' => EntryLayout::Landing])
            ->assertFormFieldVisible('show_title')
            ->fillForm(['show_title' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Entry::query()->where('slug', 'launch')->sole()->show_title);
    }

    public function test_blogs_and_posts_use_their_layout_too(): void
    {
        $blog = Entry::factory()->published()->create(['type' => EntryType::Blog, 'slug' => 'news', 'title' => 'News', 'layout' => EntryLayout::FullWidth]);
        Entry::factory()->published()->create(['type' => EntryType::Post, 'blog_id' => $blog->id, 'slug' => 'hello', 'title' => 'Hello', 'layout' => EntryLayout::Landing]);

        $this->get('/news')->assertOk()->assertSee('layout-full-width', false);
        $this->get('/news/hello')->assertOk()->assertSee('layout-landing', false)->assertSee('<h1 class="page-title visually-hidden">Hello</h1>', false);
    }

    public function test_the_layout_is_chosen_when_creating_an_entry(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->assertSchemaStateSet(['layout' => EntryLayout::Standard])
            ->fillForm(['title' => 'Launch', 'layout' => EntryLayout::Landing])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(EntryLayout::Landing, Entry::query()->where('slug', 'launch')->sole()->layout);
    }
}
