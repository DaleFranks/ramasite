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
    <h1 @class(['page-title', 'visually-hidden' => ! $entry->showsTitle()])>{{ $entry->title }}</h1>

    <x-site.publish-date :entry="$entry" />

    <article class="prose">
        {!! $entry->bodyHtml() !!}
    </article>

    <div class="post-footer">
        <a href="{{ $entry->blog->url() }}" class="back-link">&laquo; Back to {{ $entry->blog->title }}</a>
    </div>
</x-site.layout>
