<?php

namespace Tests\Feature;

use App\BlogColumns;
use App\EntryStatus;
use App\EntryType;
use App\Filament\Resources\Entries\Pages\CreateEntry;
use App\Filament\Resources\Entries\Pages\EditEntry;
use App\Filament\Resources\Entries\Pages\ListEntries;
use App\Models\Entry;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EntryResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/admin/entries')->assertRedirect('/admin/login');
    }

    public function test_the_list_shows_entries(): void
    {
        $this->actingAs(User::factory()->create());
        $entries = Entry::factory()->count(2)->create();

        Livewire::test(ListEntries::class)->assertCanSeeTableRecords($entries);
    }

    public function test_creating_an_entry_saves_it_with_the_logged_in_user_as_author(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'About RamaSite',
                'slug' => 'about',
                'body' => "## Hello\n\nSome **Markdown**.",
                'type' => EntryType::Page->value,
                'status' => EntryStatus::Published->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = Entry::sole();
        $this->assertSame('about', $entry->slug);
        $this->assertSame("## Hello\n\nSome **Markdown**.", $entry->body);
        $this->assertSame(EntryStatus::Published, $entry->status);
        $this->assertTrue($entry->author->is($user));
    }

    public function test_a_publish_date_is_entered_in_the_site_timezone_and_stored_in_utc(): void
    {
        $this->actingAs(User::factory()->create());
        Theme::current()->update(['timezone' => 'America/Los_Angeles']);

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Launch',
                'slug' => 'launch',
                'body' => 'Soon.',
                'type' => EntryType::Page->value,
                'status' => EntryStatus::Published->value,
                'published_at' => '2026-12-01 09:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-12-01 17:00:00', Entry::sole()->published_at->toDateTimeString());
    }

    public function test_the_slug_is_filled_in_from_the_title_when_creating(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm(['title' => 'Why Flat-File CMS Is the Future'])
            ->assertSchemaStateSet(['slug' => 'why-flat-file-cms-is-the-future']);
    }

    public function test_an_auto_filled_slug_is_numbered_when_the_address_is_taken(): void
    {
        $this->actingAs(User::factory()->create());
        Entry::factory()->create(['slug' => 'welcome']);
        Entry::factory()->create(['slug' => 'welcome-2']);

        Livewire::test(CreateEntry::class)
            ->fillForm(['type' => EntryType::Page->value, 'title' => 'Welcome'])
            ->assertSchemaStateSet(['slug' => 'welcome-3']);
    }

    public function test_an_auto_filled_post_slug_is_only_numbered_against_its_own_blog(): void
    {
        $this->actingAs(User::factory()->create());
        $news = Entry::factory()->blog()->create(['slug' => 'news']);
        $articles = Entry::factory()->blog()->create(['slug' => 'articles']);
        Entry::factory()->post($articles)->create(['slug' => 'welcome']);

        $component = Livewire::test(CreateEntry::class)
            ->fillForm(['type' => EntryType::Post->value, 'blog_id' => $news->id, 'title' => 'Welcome'])
            ->assertSchemaStateSet(['slug' => 'welcome']);

        $component
            ->fillForm(['blog_id' => $articles->id])
            ->assertSchemaStateSet(['slug' => 'welcome-2']);
    }

    public function test_a_slug_typed_by_hand_is_kept_when_the_title_changes(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm(['title' => 'About'])
            ->fillForm(['slug' => 'about-us'])
            ->fillForm(['title' => 'About RamaSite'])
            ->assertSchemaStateSet(['slug' => 'about-us']);
    }

    public function test_clearing_the_slug_turns_auto_fill_back_on(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm(['title' => 'About'])
            ->fillForm(['slug' => 'about-us'])
            ->fillForm(['slug' => ''])
            ->fillForm(['title' => 'About RamaSite'])
            ->assertSchemaStateSet(['slug' => 'about-ramasite']);
    }

    public function test_changing_the_title_of_an_existing_entry_keeps_its_slug(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = Entry::factory()->create(['title' => 'About', 'slug' => 'about']);

        Livewire::test(EditEntry::class, ['record' => $entry->getRouteKey()])
            ->fillForm(['title' => 'About Us'])
            ->assertSchemaStateSet(['slug' => 'about']);
    }

    public function test_a_taken_address_is_reported_as_soon_as_the_slug_is_entered(): void
    {
        $this->actingAs(User::factory()->create());
        Entry::factory()->create(['slug' => 'about']);

        Livewire::test(CreateEntry::class)
            ->fillForm(['type' => EntryType::Page->value, 'slug' => 'about'])
            ->assertHasFormErrors(['slug']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicFolderNames(): array
    {
        return [
            'css folder' => ['css'],
            'fonts folder' => ['fonts'],
            'below a folder' => ['js/tools'],
        ];
    }

    #[DataProvider('publicFolderNames')]
    public function test_a_page_cannot_take_the_name_of_a_folder_in_public(string $slug): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Clash',
                'slug' => $slug,
                'type' => EntryType::Page->value,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $this->assertDatabaseMissing('entries', ['path' => $slug]);
    }

    public function test_every_folder_in_public_is_reserved(): void
    {
        foreach (glob(public_path('*'), GLOB_ONLYDIR) as $folder) {
            $this->assertContains(basename($folder), Entry::RESERVED_PATHS, "The folder {$folder} isn't reserved.");
        }
    }

    public function test_a_page_can_share_its_name_with_a_top_level_folder(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Resources',
                'slug' => 'resources',
                'type' => EntryType::Page->value,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('entries', ['path' => 'resources']);
    }

    public function test_a_page_slug_can_contain_slashes(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Design',
                'slug' => 'services/design',
                'type' => EntryType::Page->value,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('entries', ['path' => 'services/design']);
    }

    public function test_a_post_is_created_in_its_blog(): void
    {
        $this->actingAs(User::factory()->create());
        $blog = Entry::factory()->blog()->create(['slug' => 'news']);

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Version 1.0 Released',
                'slug' => 'v1-released',
                'type' => EntryType::Post->value,
                'blog_id' => $blog->id,
                'status' => EntryStatus::Published->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Entry::query()->where('type', EntryType::Post)->sole();
        $this->assertTrue($post->blog->is($blog));
        $this->assertSame('news/v1-released', $post->path);
    }

    public function test_a_new_blog_lists_nine_posts_per_page_unless_changed(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm(['type' => EntryType::Blog->value])
            ->assertFormSet(['posts_per_page' => 9])
            ->fillForm([
                'title' => 'News',
                'slug' => 'news',
                'status' => EntryStatus::Published->value,
                'posts_per_page' => 12,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('entries', ['path' => 'news', 'posts_per_page' => 12]);
    }

    public function test_posts_per_page_is_only_shown_for_blogs(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm(['type' => EntryType::Page->value])
            ->assertFormFieldHidden('posts_per_page')
            ->fillForm(['type' => EntryType::Blog->value])
            ->assertFormFieldVisible('posts_per_page');
    }

    public function test_a_new_blog_arranges_posts_automatically_unless_changed(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm(['type' => EntryType::Blog->value])
            ->assertFormSet(['posts_columns' => BlogColumns::Automatic])
            ->fillForm([
                'title' => 'News',
                'slug' => 'news',
                'status' => EntryStatus::Published->value,
                'posts_columns' => BlogColumns::Two->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('entries', ['path' => 'news', 'posts_columns' => 'two']);
    }

    public function test_the_columns_setting_is_only_shown_for_blogs(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm(['type' => EntryType::Page->value])
            ->assertFormFieldHidden('posts_columns')
            ->fillForm(['type' => EntryType::Blog->value])
            ->assertFormFieldVisible('posts_columns');
    }

    public function test_a_post_must_have_a_blog(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Orphan',
                'slug' => 'orphan',
                'type' => EntryType::Post->value,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['blog_id' => 'required']);
    }

    public function test_a_post_slug_cannot_contain_slashes(): void
    {
        $this->actingAs(User::factory()->create());
        $blog = Entry::factory()->blog()->create();

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Post',
                'slug' => 'nested/post',
                'type' => EntryType::Post->value,
                'blog_id' => $blog->id,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'regex']);
    }

    public function test_only_blogs_can_be_chosen_as_a_posts_blog(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Entry::factory()->create();

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Post',
                'slug' => 'post',
                'type' => EntryType::Post->value,
                'blog_id' => $page->id,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['blog_id']);
    }

    public function test_the_same_post_slug_can_be_used_in_two_blogs(): void
    {
        $this->actingAs(User::factory()->create());
        $news = Entry::factory()->blog()->create(['slug' => 'news']);
        Entry::factory()->post(Entry::factory()->blog()->create(['slug' => 'articles']))->create(['slug' => 'welcome']);

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Welcome',
                'slug' => 'welcome',
                'type' => EntryType::Post->value,
                'blog_id' => $news->id,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('entries', ['path' => 'news/welcome']);
    }

    public function test_a_page_cannot_take_the_address_of_an_existing_post(): void
    {
        $this->actingAs(User::factory()->create());
        Entry::factory()->post(Entry::factory()->blog()->create(['slug' => 'news']))->create(['slug' => 'welcome']);

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Welcome',
                'slug' => 'news/welcome',
                'type' => EntryType::Page->value,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_a_blog_cant_move_to_where_one_of_its_posts_would_clash(): void
    {
        $this->actingAs(User::factory()->create());
        $blog = Entry::factory()->blog()->create(['slug' => 'news']);
        Entry::factory()->post($blog)->create(['slug' => 'welcome']);
        Entry::factory()->create(['slug' => 'blog/welcome']);

        Livewire::test(EditEntry::class, ['record' => $blog->getRouteKey()])
            ->fillForm(['slug' => 'blog'])
            ->call('save')
            ->assertHasFormErrors(['slug']);

        $this->assertSame('news', $blog->refresh()->path);
    }

    public function test_a_blog_with_posts_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $blog = Entry::factory()->blog()->create();
        Entry::factory()->post($blog)->create();

        Livewire::test(EditEntry::class, ['record' => $blog->getRouteKey()])
            ->callAction('delete');

        $this->assertModelExists($blog);
    }

    public function test_an_empty_blog_can_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $blog = Entry::factory()->blog()->create();

        Livewire::test(EditEntry::class, ['record' => $blog->getRouteKey()])
            ->callAction('delete');

        $this->assertModelMissing($blog);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSlugs(): array
    {
        return [
            'uppercase' => ['About'],
            'space' => ['about us'],
            'leading slash' => ['/about'],
            'trailing slash' => ['about/'],
            'double hyphen' => ['about--us'],
        ];
    }

    #[DataProvider('invalidSlugs')]
    public function test_an_invalid_slug_is_rejected(string $slug): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'About',
                'slug' => $slug,
                'type' => EntryType::Page->value,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'regex']);

        $this->assertDatabaseCount('entries', 0);
    }

    public function test_a_slug_already_used_by_another_entry_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        Entry::factory()->create(['slug' => 'about']);

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'About',
                'slug' => 'about',
                'type' => EntryType::Page->value,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_an_address_used_by_the_system_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateEntry::class)
            ->fillForm([
                'title' => 'Admin',
                'slug' => 'admin/tools',
                'type' => EntryType::Page->value,
                'status' => EntryStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_an_entry_can_be_saved_without_changing_its_own_slug(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = Entry::factory()->create(['slug' => 'about']);

        Livewire::test(EditEntry::class, ['record' => $entry->getRouteKey()])
            ->fillForm(['title' => 'About Us'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('About Us', $entry->refresh()->title);
        $this->assertSame('about', $entry->slug);
    }
}
