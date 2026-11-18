# RamaSite documentation

How each screen in the admin (at `/admin`) works.

**Start here**

- [Getting started](getting-started.md): the first things to set up, and the Dashboard
- [Writing content](writing-content.md): Markdown, and the shortcodes that place images, file links, videos, cards, modules, sliders, forms and social links

**Content**

- [Pages & Posts](pages-and-posts.md): pages, blogs, posts and the menu
- [Images](images.md): the image library, and replacing an image
- [Files](files.md): PDFs, documents, spreadsheets and ZIPs to link to, and replacing a file
- [Cards and Modules](cards-and-modules.md): reusable boxes and sections, and the footer
- [Sliders](sliders.md): rotating images
- [Forms](forms.md): contact forms, newsletter sign-ups and their submissions
- [Social links](social-links.md): rows of social media logos linking to your profiles
- [Redirects](redirects.md): sending old addresses to new ones, and `www.` to your main address

**Settings**

- [Theme](theme.md): logo, colors, fonts, headings, header, top bar, footer and layout
- [Email](email.md): how the site sends email
- [Analytics & Verification](analytics.md): Google Analytics, Tag Manager, Meta Pixel, Search Console and Bing verification, and code from any other service
- [Users and security](users-and-security.md): admin accounts, your profile, two-step login and passwords
- [Backup](backup.md): downloading a backup and restoring one
- [Upgrading](upgrading.md): installing a new version, what looks different after upgrading, the automatic database update, and what to do if it can't run

Search engines are looked after automatically: every site has a sitemap at `/sitemap.xml` that lists everything published, and a `robots.txt` that points to it. Each blog also has an [RSS feed](pages-and-posts.md#rss-feed). Every page also names its one true address (a "canonical" link), so search engines treat `www.` and plain visits, and addresses with extra bits like `?utm_source=...`, as the same page. That address uses the site's main domain, the one it was set up on.
