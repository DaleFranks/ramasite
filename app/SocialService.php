<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * The services a social link can point to, each with its logo.
 *
 * The brand logos and colors come from Simple Icons 16.38.0
 * (https://simpleicons.org), which is CC0: free to use with no credit needed.
 * The logos are trademarks of their owners. The Email and Website logos were
 * drawn for RamaSite. LinkedIn has no logo here: its rules only allow its own
 * logo file, downloaded from LinkedIn by the site owner, so each LinkedIn link
 * shows an image the owner chose from the image library.
 */
enum SocialService: string implements HasLabel
{
    case Behance = 'behance';
    case Bluesky = 'bluesky';
    case Discord = 'discord';
    case Dribbble = 'dribbble';
    case Email = 'email';
    case Etsy = 'etsy';
    case Facebook = 'facebook';
    case GitHub = 'github';
    case GoogleBusiness = 'google-business';
    case Instagram = 'instagram';
    case LinkedIn = 'linkedin';
    case Mastodon = 'mastodon';
    case Medium = 'medium';
    case Nextdoor = 'nextdoor';
    case Patreon = 'patreon';
    case Pinterest = 'pinterest';
    case Reddit = 'reddit';
    case Rss = 'rss';
    case Snapchat = 'snapchat';
    case SoundCloud = 'soundcloud';
    case Spotify = 'spotify';
    case Substack = 'substack';
    case Telegram = 'telegram';
    case Threads = 'threads';
    case TikTok = 'tiktok';
    case Tripadvisor = 'tripadvisor';
    case Twitch = 'twitch';
    case Vimeo = 'vimeo';
    case Website = 'website';
    case WhatsApp = 'whatsapp';
    case X = 'x';
    case Yelp = 'yelp';
    case YouTube = 'youtube';

    /**
     * The name shown in the admin's list of services.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::LinkedIn => 'LinkedIn (uses LinkedIn\'s own logo file)',
            self::X => 'X (Twitter)',
            default => $this->title(),
        };
    }

    /**
     * The service's name, which screen readers announce for its link.
     */
    public function title(): string
    {
        return match ($this) {
            self::Behance => 'Behance',
            self::Bluesky => 'Bluesky',
            self::Discord => 'Discord',
            self::Dribbble => 'Dribbble',
            self::Email => 'Email',
            self::Etsy => 'Etsy',
            self::Facebook => 'Facebook',
            self::GitHub => 'GitHub',
            self::GoogleBusiness => 'Google Business Profile',
            self::Instagram => 'Instagram',
            self::LinkedIn => 'LinkedIn',
            self::Mastodon => 'Mastodon',
            self::Medium => 'Medium',
            self::Nextdoor => 'Nextdoor',
            self::Patreon => 'Patreon',
            self::Pinterest => 'Pinterest',
            self::Reddit => 'Reddit',
            self::Rss => 'RSS feed',
            self::Snapchat => 'Snapchat',
            self::SoundCloud => 'SoundCloud',
            self::Spotify => 'Spotify',
            self::Substack => 'Substack',
            self::Telegram => 'Telegram',
            self::Threads => 'Threads',
            self::TikTok => 'TikTok',
            self::Tripadvisor => 'Tripadvisor',
            self::Twitch => 'Twitch',
            self::Vimeo => 'Vimeo',
            self::Website => 'Website',
            self::WhatsApp => 'WhatsApp',
            self::X => 'X',
            self::Yelp => 'Yelp',
            self::YouTube => 'YouTube',
        };
    }

    /**
     * The brand's official color. Null for Email and Website, which have no
     * brand and take the theme's link color, and for LinkedIn, whose logo file
     * keeps its own colors.
     */
    public function brandColor(): ?string
    {
        return match ($this) {
            self::Behance => '#1769FF',
            self::Bluesky => '#1185FE',
            self::Discord => '#5865F2',
            self::Dribbble => '#EA4C89',
            self::Etsy => '#F16521',
            self::Facebook => '#0866FF',
            self::GitHub => '#181717',
            self::GoogleBusiness => '#4285F4',
            self::Instagram => '#FF0069',
            self::Mastodon => '#6364FF',
            self::Medium, self::Patreon, self::Threads, self::TikTok, self::X => '#000000',
            self::Nextdoor => '#8ED500',
            self::Pinterest => '#BD081C',
            self::Reddit => '#FF4500',
            self::Rss => '#FFA500',
            self::Snapchat => '#FFFC00',
            self::SoundCloud => '#FF5500',
            self::Spotify => '#1ED760',
            self::Substack => '#FF6719',
            self::Telegram => '#26A5E4',
            self::Tripadvisor => '#34E0A1',
            self::Twitch => '#9146FF',
            self::Vimeo => '#1AB7EA',
            self::WhatsApp => '#25D366',
            self::Yelp => '#FF1A1A',
            self::YouTube => '#FF0000',
            self::Email, self::Website, self::LinkedIn => null,
        };
    }

    /**
     * Whether the link shows a logo the site owner supplies (LinkedIn's)
     * rather than one built in.
     */
    public function usesOwnLogo(): bool
    {
        return $this === self::LinkedIn;
    }

    /**
     * The built-in logo as an SVG drawn in the text color, hidden from screen
     * readers because its link carries the name. Null for LinkedIn.
     */
    public function logoSvg(): ?string
    {
        if ($this->usesOwnLogo()) {
            return null;
        }

        $svg = trim((string) file_get_contents(resource_path('svg/social/'.$this->value.'.svg')));

        return preg_replace('/^<svg /', '<svg aria-hidden="true" focusable="false" fill="currentColor" ', $svg);
    }

    /**
     * The link's address: an email address becomes a mailto: link.
     */
    public function href(string $address): string
    {
        if ($this === self::Email) {
            return 'mailto:'.preg_replace('/^mailto:/i', '', $address);
        }

        return $address;
    }
}
