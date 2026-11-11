<x-site.layout
    :title="$entry->title"
    :description="$entry->meta_description"
    :keywords="$entry->meta_keywords"
    :share-image="$entry->shareImage()"
    share-type="article"
    :layout="$entry->layout"
    :canonical-url="$entry->canonicalUrl()"
    :preview-notice="$previewNotice"
    :edit-url="$editUrl"
    :current-path="$entry->blog->path"
>
    @if ($entry->layout->showsTitle())
        <h1 class="page-title">{{ $entry->title }}</h1>
    @endif

    @if ($entry->published_at)
        <div class="post-meta">
            Published on <time datetime="{{ \App\Models\Theme::localTime($entry->published_at)->toDateString() }}">{{ \App\Models\Theme::localTime($entry->published_at)->format('F j, Y') }}</time>
        </div>
    @endif

    <article class="prose">
        {!! $entry->bodyHtml() !!}
    </article>

    <div class="post-footer">
        <a href="{{ $entry->blog->url() }}" class="back-link">&laquo; Back to {{ $entry->blog->title }}</a>
    </div>
</x-site.layout>
