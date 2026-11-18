@props([
    'set',
    'links',
    'alignment' => null,
])

<span class="social-links social-{{ $set->logo_style->value }}{{ $alignment ? ' align-'.$alignment : '' }}" style="--social-size: {{ $set->logoSize() }}px{{ $set->colorVariable() ? '; '.$set->colorVariable() : '' }}">
@foreach ($links as $link)
    <a href="{{ $link['href'] }}" class="social-link social-{{ $link['service']->value }}" aria-label="{{ $link['service']->title() }}"
        @if ($set->logo_style === \App\SocialLogoStyle::Color && $link['service']->brandColor()) style="--social-color: {{ $link['service']->brandColor() }}" @endif
        @if ($set->opens_in_new_tab && $link['service'] !== \App\SocialService::Email) target="_blank" rel="me noopener" @else rel="me" @endif>
        @if ($link['image'])
            <img src="{{ $link['image']->url() }}" alt="" width="{{ $set->logoSize() }}" height="{{ $set->logoSize() }}">
        @else
            {!! $link['service']->logoSvg() !!}
        @endif
    </a>
@endforeach
</span>
