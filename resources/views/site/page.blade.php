<x-site.layout
    :title="$entry->title"
    :description="$entry->meta_description"
    :keywords="$entry->meta_keywords"
    :share-image="$entry->shareImage()"
    :layout="$entry->layout"
    :canonical-url="$entry->canonicalUrl()"
    :preview-notice="$previewNotice"
    :edit-url="$editUrl"
    :current-path="$entry->path"
>
    <h1 @class(['page-title', 'visually-hidden' => ! $entry->showsTitle()])>{{ $entry->title }}</h1>

    <x-site.publish-date :entry="$entry" />

    <article class="prose">
        {!! $entry->bodyHtml() !!}
    </article>
</x-site.layout>
