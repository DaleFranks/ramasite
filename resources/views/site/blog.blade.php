<x-site.layout
    :title="$entry->title"
    :description="$entry->meta_description"
    :keywords="$entry->meta_keywords"
    :share-image="$entry->shareImage()"
    :layout="$entry->layout"
    :canonical-url="$entry->canonicalUrl($posts->currentPage())"
    :preview-notice="$previewNotice"
    :edit-url="$editUrl"
    :current-path="$entry->path"
>
    <h1 @class(['page-title', 'visually-hidden' => ! $entry->showsTitle()])>{{ $entry->title }}</h1>

    <x-site.publish-date :entry="$entry" />

    <article class="prose">
        {!! $entry->bodyHtml() !!}
    </article>

    <div @class(array_filter(['card-grid', 'blog-feed', $entry->posts_columns?->cssClass()]))>
        @forelse ($posts as $post)
            <article class="card feed-item">
                @isset($previewImages[$post->id])
                    <a href="{{ $post->url() }}" class="feed-image" tabindex="-1" aria-hidden="true">
                        <img src="{{ $previewImages[$post->id] }}" alt="" loading="lazy">
                    </a>
                @endisset
                <h2 class="feed-title">
                    <a href="{{ $post->url() }}">{{ $post->title }}</a>
                </h2>
                @if ($post->showsPublishDate() && $post->published_at)
                    <div class="feed-date">{{ \App\Models\Theme::localTime($post->published_at)->format('F j, Y') }}</div>
                @endif
                <p class="feed-excerpt">{{ $post->excerpt() }}</p>
                <a href="{{ $post->url() }}" class="read-more">Read More &raquo;</a>
            </article>
        @empty
            <p class="no-posts">No posts yet.</p>
        @endforelse
    </div>

    @if ($posts->hasPages())
        <nav class="pagination" aria-label="Pages of posts">
            @if (! $posts->onFirstPage())
                <a href="{{ $posts->previousPageUrl() }}" class="prev-page">&laquo; Newer Posts</a>
            @endif
            <span class="page-number">Page {{ $posts->currentPage() }} of {{ $posts->lastPage() }}</span>
            @if ($posts->hasMorePages())
                <a href="{{ $posts->nextPageUrl() }}" class="next-page">Older Posts &raquo;</a>
            @endif
        </nav>
    @endif
</x-site.layout>
