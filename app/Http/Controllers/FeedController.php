<?php

namespace App\Http\Controllers;

use App\EntryType;
use App\Models\Entry;
use App\Models\Redirect;
use App\Models\Theme;
use App\SiteAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Each published blog's RSS feed, at its address followed by /feed.xml. Like
 * the sitemap, it's built from what's published, with nothing to set up. Each
 * post is a summary: its title, date, featured image and the same first
 * sentences the blog's cards show, linking to the post for the rest.
 */
class FeedController extends Controller
{
    /**
     * How many of the newest posts a feed lists.
     */
    public const LISTED_POSTS = 20;

    public function show(Request $request, string $blogPath): Response|RedirectResponse
    {
        $blog = Entry::query()
            ->published()
            ->where('type', EntryType::Blog)
            ->where('path', $blogPath)
            ->first();

        // With no blog there, the address may still have been redirected, such as an old site's feed
        if ($blog === null) {
            $redirect = Redirect::findFor($blogPath.'/feed.xml');
            abort_if($redirect === null, 404);

            return $redirect->follow($request);
        }

        $posts = $blog->posts()
            ->published()
            ->orderByRaw('coalesce(published_at, created_at) desc')
            ->limit(self::LISTED_POSTS)
            ->get();

        $siteAddress = SiteAddress::canonicalOrigin();
        $images = collect(Entry::previewImageUrls($posts))
            ->map(fn (string $url): string => str_starts_with($url, '/') ? $siteAddress.$url : $url);

        $items = $posts->map(fn (Entry $post): array => [
            'title' => self::xmlText($post->title),
            'url' => $post->canonicalUrl(),
            'date' => ($post->published_at ?? $post->created_at)->toRssString(),
            'description' => self::xmlText(
                ($images->has($post->id) ? '<p><img src="'.e($images->get($post->id)).'" alt=""></p>' : '')
                .'<p>'.e($post->excerpt()).'</p>'
            ),
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".view('site.feed', [
            'title' => self::xmlText($blog->title.' | '.Theme::current()->site_name),
            'url' => $blog->canonicalUrl(),
            'feedUrl' => $blog->feedUrl(),
            'description' => self::xmlText(filled($blog->meta_description)
                ? $blog->meta_description
                : 'The latest posts from '.$blog->title.'.'),
            'items' => $items,
        ])->render();

        return response($xml)->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    /**
     * Text with the control characters XML doesn't allow taken out, since a
     * single one would make the whole feed unreadable.
     */
    private static function xmlText(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }
}
