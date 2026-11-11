<?php

namespace Tests\Feature;

use App\EntryStatus;
use App\EntryType;
use App\Models\Entry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_published_scope_only_returns_entries_that_are_live(): void
    {
        $live = Entry::factory()->published()->create();
        $liveWithoutDate = Entry::factory()->create([
            'status' => EntryStatus::Published,
            'published_at' => null,
        ]);
        Entry::factory()->create(['status' => EntryStatus::Draft]);
        Entry::factory()->published()->create(['published_at' => now()->addDay()]);

        $this->assertEqualsCanonicalizing(
            [$live->id, $liveWithoutDate->id],
            Entry::published()->pluck('id')->all(),
        );
    }

    public function test_the_excerpt_leaves_out_headings_images_and_shortcodes(): void
    {
        $entry = new Entry(['body' => "# Contact us\n\n![Team](team.jpg) Get in touch below.\n\n[form:contact]\n\n[image:map.png|right] [youtube:https://youtu.be/abcdefghijk]\n\nWe reply fast. Usually the same day. Never on Sundays."]);

        $this->assertSame('Get in touch below. We reply fast. Usually the same day.', $entry->excerpt());
    }

    public function test_deleting_a_parent_moves_its_children_to_the_top_level(): void
    {
        $parent = Entry::factory()->create();
        $child = Entry::factory()->create(['parent_id' => $parent->id]);

        $parent->delete();

        $this->assertNull($child->refresh()->parent_id);
    }

    public function test_changing_a_blogs_slug_moves_its_posts(): void
    {
        $blog = Entry::factory()->blog()->create(['slug' => 'blog']);
        $post = Entry::factory()->post($blog)->create(['slug' => 'hello']);
        $otherPost = Entry::factory()->post()->create(['slug' => 'hello']);
        $this->assertSame('blog/hello', $post->path);

        $blog->update(['slug' => 'articles']);

        $this->assertSame('articles/hello', $post->refresh()->path);
        $this->assertSame($otherPost->blog->slug.'/hello', $otherPost->refresh()->path);
    }

    public function test_a_blog_whose_posts_cant_move_stays_where_it_was_with_all_its_posts(): void
    {
        $blog = Entry::factory()->blog()->create(['slug' => 'news']);
        $first = Entry::factory()->post($blog)->create(['slug' => 'first']);
        $clashing = Entry::factory()->post($blog)->create(['slug' => 'welcome']);
        Entry::factory()->create(['slug' => 'blog/welcome']);

        try {
            $blog->update(['slug' => 'blog']);
            $this->fail('The clashing post should have stopped the move.');
        } catch (QueryException) {
        }

        $this->assertSame('news', $blog->refresh()->path);
        $this->assertSame('news/first', $first->refresh()->path);
        $this->assertSame('news/welcome', $clashing->refresh()->path);
    }

    public function test_an_entry_that_is_not_a_post_has_no_blog(): void
    {
        $blog = Entry::factory()->blog()->create(['slug' => 'news']);
        $post = Entry::factory()->post($blog)->create(['slug' => 'about']);

        $post->update(['type' => EntryType::Page]);

        $this->assertNull($post->blog_id);
        $this->assertSame('about', $post->path);
    }
}
