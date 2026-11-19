<?php

namespace Tests\Feature;

use App\Markdown;
use App\Models\Entry;
use Tests\TestCase;

class MarkdownAttributesTest extends TestCase
{
    public function test_a_link_with_a_target_opens_in_a_new_tab(): void
    {
        $html = Markdown::toHtml('Visit [our shop](https://example.com){target=_blank} today.');

        $this->assertStringContainsString('<a target="_blank" href="https://example.com" rel="noopener noreferrer">our shop</a> today.', $html);
    }

    public function test_a_link_without_attributes_is_unchanged(): void
    {
        $html = Markdown::toHtml('Visit [our shop](https://example.com) today.');

        $this->assertStringContainsString('<a href="https://example.com">our shop</a> today.', $html);
    }

    public function test_a_class_can_be_added_to_a_paragraph(): void
    {
        $html = Markdown::toHtml("Small print here.\n{.note}");

        $this->assertStringContainsString('<p class="note">Small print here.</p>', $html);
    }

    public function test_the_excerpt_leaves_out_link_attributes(): void
    {
        $entry = new Entry(['body' => 'Visit [our shop](https://example.com){target=_blank} today.']);

        $this->assertSame('Visit our shop today.', $entry->excerpt());
    }
}
