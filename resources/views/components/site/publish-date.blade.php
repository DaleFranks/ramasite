@props([
    'entry',
])

{{-- The publish date under a page's or post's title, when its "Show the publish date" switch is on --}}
@if ($entry->showsPublishDate() && $entry->published_at)
    <div class="post-meta">
        Published on <time datetime="{{ \App\Models\Theme::localTime($entry->published_at)->toDateString() }}">{{ \App\Models\Theme::localTime($entry->published_at)->format('F j, Y') }}</time>
    </div>
@endif
