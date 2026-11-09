@props([
    'slider',
    'slides',
])

<div
    @class(['slider', 'animation-'.$slider->animation->value, 'edge-to-edge' => $slider->edge_to_edge])
    @if ($slider->seconds_per_slide) data-seconds="{{ $slider->seconds_per_slide }}" @endif
    role="region"
    aria-roledescription="carousel"
    aria-label="{{ $slider->name }}"
>
    <div class="slider-track">
        @foreach ($slides as $slide)
            <figure @class(['slide', 'is-active' => $loop->first]) aria-roledescription="slide" aria-label="{{ $loop->iteration }} of {{ $loop->count }}">
                <img
                    src="{{ $slide->image->url() }}"
                    alt="{{ $slide->image->alt_text }}"
                    width="{{ $slide->image->width }}"
                    height="{{ $slide->image->height }}"
                    @unless ($loop->first) loading="lazy" @endunless
                >
                @if (filled($slide->caption))
                    <figcaption class="slide-caption">{{ $slide->caption }}</figcaption>
                @endif
            </figure>
        @endforeach
    </div>

    @if ($slides->count() > 1)
        <button type="button" class="slider-prev" aria-label="Previous slide">&#10094;</button>
        <button type="button" class="slider-next" aria-label="Next slide">&#10095;</button>
        <div class="slider-dots">
            @foreach ($slides as $slide)
                <button type="button" aria-label="Show slide {{ $loop->iteration }}" @if ($loop->first) aria-current="true" @endif></button>
            @endforeach
        </div>
        <script src="{{ asset('js/slider.js') }}?v={{ filemtime(public_path('js/slider.js')) }}" defer></script>
    @endif
</div>
