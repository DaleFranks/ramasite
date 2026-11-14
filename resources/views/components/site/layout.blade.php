@props([
    'title',
    'description' => null,
    'keywords' => null,
    'currentPath' => null,
    'layout' => \App\EntryLayout::Standard,
    'shareImage' => null,
    'shareType' => 'website',
    'previewNotice' => null,
    'canonicalUrl' => null,
    'editUrl' => null,
])

@php
    // Visitors are tracked, but not previews or anyone signed in to the admin
    $tracksThisVisit = $analytics->tracksThisVisit() && ! $previewNotice;

    // The picture for link previews on social media: the page's own image, or else the logo
    $sharePicture = $shareImage ?? ($theme->logoUrl()
        ? ['url' => \App\SiteAddress::canonicalOrigin().$theme->logoUrl(), 'width' => null, 'height' => null]
        : null);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $title }} | {{ $theme->site_name }}</title>
    <x-site.analytics :settings="$analytics" :tracks="$tracksThisVisit" place="head" />
    @if ($previewNotice)
        <meta name="robots" content="noindex, nofollow">
    @elseif ($canonicalUrl)
        <link rel="canonical" href="{{ $canonicalUrl }}">
    @endif
    @if (filled($description))
        <meta name="description" content="{{ $description }}">
    @endif
    @if (filled($keywords))
        <meta name="keywords" content="{{ $keywords }}">
    @endif

    <meta property="og:title" content="{{ $title }}">
    @if (filled($description))
        <meta property="og:description" content="{{ $description }}">
    @endif
    <meta property="og:type" content="{{ $shareType }}">
    <meta property="og:url" content="{{ $canonicalUrl ?? url()->current() }}">
    <meta property="og:site_name" content="{{ $theme->site_name }}">
    @if ($sharePicture)
        <meta property="og:image" content="{{ $sharePicture['url'] }}">
        @if ($sharePicture['width'] && $sharePicture['height'])
            <meta property="og:image:width" content="{{ $sharePicture['width'] }}">
            <meta property="og:image:height" content="{{ $sharePicture['height'] }}">
        @endif
    @endif
    <meta name="twitter:card" content="{{ $shareImage ? 'summary_large_image' : 'summary' }}">

    @foreach ($feeds as $feed)
        <link rel="alternate" type="application/rss+xml" title="{{ $feed->title }}" href="{{ $feed->feedUrl() }}">
    @endforeach
    @if ($theme->faviconUrl())
        <link rel="icon" href="{{ $theme->faviconUrl() }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="{{ $theme->googleFontsUrl() }}">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ filemtime(public_path('css/site.css')) }}">
    <style>
        :root {
        @foreach ($theme->cssVariables() as $name => $value)
            {{ $name }}: {!! str_replace('<', '', $value) !!};
        @endforeach
        }
        {!! $theme->safeCustomCss() !!}
    </style>
    <x-site.analytics :settings="$analytics" :tracks="$tracksThisVisit" place="head-end" />
</head>
<body class="{{ $theme->bodyClasses() }} layout-{{ $layout->value }}{{ $previewNotice ? ' has-preview-bar' : '' }}">
    <x-site.analytics :settings="$analytics" :tracks="$tracksThisVisit" place="body-start" />
    <header class="site-header">
        @if ($theme->hasTopBar())
            <div class="top-bar theme-{{ $theme->top_bar_style->value }} align-{{ $theme->top_bar_alignment->value }}">
                <div class="top-bar-container">{{ $theme->topBarHtml() }}</div>
            </div>
        @endif
        <div class="header-container">
            <div class="header-logo">
                <a href="{{ route('home') }}">
                    @if ($theme->logoUrl())
                        <img src="{{ $theme->logoUrl() }}" alt="{{ $theme->site_name }}" class="logo-icon">
                    @endif
                    @if ($theme->showsSiteName())
                        <span class="logo-text">{{ $theme->site_name }}</span>
                    @endif
                </a>
            </div>

            <button class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false" aria-controls="site-nav">
                <span class="hamburger"></span>
            </button>

            <nav class="header-nav" id="site-nav" aria-label="Main menu">
                <x-site.menu :items="$menu" :current-path="$currentPath" />
            </nav>
        </div>
    </header>

    <script>
        // Fold the menu into the menu button whenever the site name and menu don't
        // fit on one line, and always on narrow screens. Runs before the header is
        // first shown, again once the web fonts have loaded, and when the window is
        // resized. The fit is judged with the header at full height, so it never
        // changes while scrolling.
        (function () {
            var header = document.querySelector('.site-header');
            var container = header.querySelector('.header-container');
            var menu = header.querySelector('.nav-menu');
            var frame;

            function fitMenu() {
                var wasScrolled = header.classList.contains('scrolled');

                header.classList.remove('scrolled');
                document.body.classList.remove('nav-collapsed');

                var overflows = container.scrollWidth > container.clientWidth + 1
                    || (menu !== null && menu.scrollWidth > menu.clientWidth + 1);
                var isCollapsed = window.innerWidth < 992 || overflows;

                document.body.classList.toggle('nav-collapsed', isCollapsed);
                header.classList.toggle('scrolled', wasScrolled);

                if (! isCollapsed) {
                    header.querySelector('.header-nav').classList.remove('open');
                    header.querySelector('.nav-toggle').classList.remove('open');
                    header.querySelector('.nav-toggle').setAttribute('aria-expanded', 'false');
                }
            }

            fitMenu();

            if (document.fonts) {
                document.fonts.ready.then(fitMenu);
            }

            window.addEventListener('resize', function () {
                cancelAnimationFrame(frame);
                frame = requestAnimationFrame(fitMenu);
            });
        })();
    </script>

    <main class="site-main">
        <div class="content-container">
            {{ $slot }}
        </div>
    </main>

    <footer class="site-footer theme-{{ $theme->footer_style->value }}">
        <div class="footer-container">
            {!! $footer->html($menu) !!}
        </div>
    </footer>

    @if ($previewNotice)
        <div class="preview-bar" role="status">
            <span><strong>Preview.</strong> {{ $previewNotice }}</span>
            @if ($editUrl)
                <a href="{{ $editUrl }}">Edit</a>
            @endif
        </div>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var header = document.querySelector('.site-header');
            var navToggle = document.querySelector('.nav-toggle');
            var headerNav = document.querySelector('.header-nav');

            function scrollHeader() {
                var scrollValue = window.scrollY || document.documentElement.scrollTop;
                header.classList.toggle('scrolled', scrollValue > 50);
            }

            navToggle.addEventListener('click', function () {
                var isOpen = headerNav.classList.toggle('open');
                navToggle.classList.toggle('open', isOpen);
                navToggle.setAttribute('aria-expanded', isOpen);
            });

            scrollHeader();
            window.addEventListener('scroll', scrollHeader, { passive: true });
        });
    </script>
    <x-site.analytics :settings="$analytics" :tracks="$tracksThisVisit" place="body-end" />
</body>
</html>
