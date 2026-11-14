<?php

namespace Tests\Feature;

use App\EntryStatus;
use App\EntryType;
use App\Filament\Resources\Entries\Pages\CreateEntry;
use App\Filament\Resources\Entries\Pages\EditEntry;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every published page and post has a publish date, and its "Show the publish
 * date" switch decides whether visitors see it: on for posts, off for pages.
 */
class PublishDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_with_no_date_records_the_moment_and_keeps_it(): void
    {
        $this->travelTo('2026-11-13 18:00:00');
        $draft = Entry::factory()->create(['status' => EntryStatus::Draft, 'published_at' => null]);

        $this->assertNull($draft->published_at);

        $draft->update(['status' => EntryStatus::Published]);
        $this->assertSame('2026-11-13 18:00:00', $draft->refresh()->published_at->toDateTimeString());

        $this->travelTo('2026-12-01 09:00:00');
        $draft->update(['title' => 'Edited later']);
        $this->assertSame('2026-11-13 18:00:00', $draft->refresh()->published_at->toDateTimeString());
    }

    public function test_the_editor_shows_the_date_once_published(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-11-13 18:00:00');
        $entry = Entry::factory()->create(['status' => EntryStatus::Draft, 'published_at' => null]);

        Livewire::test(EditEntry::class, ['record' => $entry->getRouteKey()])
            ->fillForm(['status' => EntryStatus::Published])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet(['published_at' => '2026-11-13 18:00:00']);
    }

    public function test_posts_show_their_date_and_pages_dont_until_switched(): void
    {
        $this->travelTo('2026-11-13 18:00:00');
        $blog = Entry::factory()->blog()->published()->create(['slug' => 'news', 'published_at' => null]);
        $post = Entry::factory()->post($blog)->published()->create(['slug' => 'launch', 'published_at' => null]);
        $page = Entry::factory()->published()->create(['slug' => 'about', 'published_at' => null]);

        $this->get('/news/launch')->assertSee('Published on');
        $this->get('/news')->assertSee('<div class="feed-date">November 13, 2026</div>', false)->assertDontSee('<div class="post-meta">', false);
        $this->get('/about')->assertDontSee('Published on');

        $post->update(['show_publish_date' => false]);
        $page->update(['show_publish_date' => true]);
        $blog->update(['show_publish_date' => true]);

        $this->get('/news/launch')->assertDontSee('Published on');
        $this->get('/news')->assertDontSee('<div class="feed-date">', false)->assertSee('<div class="post-meta">', false);
        $this->get('/about')->assertSee('Published on <time datetime="2026-11-13">November 13, 2026</time>', false);
    }

    public function test_a_new_entrys_switch_follows_its_type(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->assertSchemaStateSet(['show_publish_date' => false])
            ->fillForm(['type' => EntryType::Post])
            ->assertSchemaStateSet(['show_publish_date' => true]);

        Livewire::withQueryParams(['type' => 'post'])
            ->test(CreateEntry::class)
            ->assertSchemaStateSet(['show_publish_date' => true]);
    }

    public function test_the_update_gives_published_entries_without_a_date_the_day_they_were_created(): void
    {
        $migration = 'database/migrations/2026_11_14_043059_add_show_publish_date_to_entries_table.php';
        Artisan::call('migrate:rollback', ['--path' => $migration]);

        $published = Entry::factory()->published()->create(['created_at' => '2026-03-01 10:00:00']);
        $draft = Entry::factory()->create(['created_at' => '2026-03-01 10:00:00']);
        DB::table('entries')->update(['published_at' => null]);

        Artisan::call('migrate', ['--path' => $migration]);

        $this->assertSame('2026-03-01 10:00:00', $published->refresh()->published_at->toDateTimeString());
        $this->assertNull($draft->refresh()->published_at);
        $this->assertNull($published->show_publish_date);
    }
}
