@props([
    'slider',
    'slides',
])

<div
    @class(array_filter(['slider', 'animation-'.$slider->animation->value, $slider->arrow_style->cssClass(), 'edge-to-edge' => $slider->edge_to_edge]))
    @if ($slider->arrowColorVariables()) style="{{ $slider->arrowColorVariables() }}" @endif
    @if ($slider->seconds_per_slide) data-seconds="{{ $slider->seconds_per_slide }}" @endif
    role="region"
    aria-roledescription="carousel"
    aria-label="{{ $slider->name }}"
>
    <div class="slider-track">
        @foreach ($slides as $slide)
            <figure @class(array_filter(['slide', $slide->zoom->cssClass(), 'is-active' => $loop->first])) aria-roledescription="slide" aria-label="{{ $loop->iteration }} of {{ $loop->count }}">
                <img
                    src="{{ $slide->image->url() }}"
                    alt="{{ $slide->image->alt_text }}"
                    width="{{ $slide->image->width }}"
                    height="{{ $slide->image->height }}"
                    @unless ($loop->first) loading="lazy" @endunless
                >
                @if (filled($slide->caption))
                    <figcaption @class(array_filter(['slide-caption', $slide->caption_position->cssClass()]))@if ($slide->captionStyle()) style="{{ $slide->captionStyle() }}"@endif>{!! nl2br(e($slide->caption)) !!}</figcaption>
                @endif
            </figure>
        @endforeach
    </div>

    @if ($slides->count() > 1)
        <button type="button" class="slider-prev" aria-label="Previous slide"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">{!! $slider->arrow_style->iconShape() !!}</svg></button>
        <button type="button" class="slider-next" aria-label="Next slide"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">{!! $slider->arrow_style->iconShape() !!}</svg></button>
        <div class="slider-dots">
            @foreach ($slides as $slide)
                <button type="button" aria-label="Show slide {{ $loop->iteration }}" @if ($loop->first) aria-current="true" @endif></button>
            @endforeach
        </div>
        <script src="{{ asset('js/slider.js') }}?v={{ filemtime(public_path('js/slider.js')) }}" defer></script>
    @endif
</div>
