# Writing content

Pages, posts, cards and modules are written in **Markdown**, a simple way of formatting text with ordinary characters. The editor has buttons for the common formatting.

## Markdown basics

| Write | To get |
|---|---|
| `## A heading` | A heading (`#` is the largest, `######` the smallest) |
| `**bold**` and `*italic*` | **bold** and *italic* |
| `[our services](/services)` | A link. Addresses on your own site can start with `/`; if the site is in a folder (such as `example.com/blog`), the folder is added automatically. |
| `- item` on each line | A bulleted list |
| `1. item` on each line | A numbered list |
| `> quoted text` | A quotation |
| A blank line | A new paragraph |

HTML can also be used where Markdown isn't enough.

A page's title is already shown above its content, so if the content starts with a `# Heading` line, that line is left out.

## Shortcodes

Shortcodes are written in square brackets and are replaced with something when the page is shown. A shortcode naming something that doesn't exist is shown as written, so a mistake is easy to spot.

To show a shortcode on a page without it being replaced, such as when explaining how to use one, put it in backticks (`` `[form:contact]` ``) or in a code block between lines of three backticks (```` ``` ````).

### Images

To add an image, put the cursor where you want it and click **Insert image**, just above the editor. Then choose one of these:

- **Image library**: search for an image you've already uploaded.
- **Upload**: add a new image to the library.
- **Image address**: use an image kept somewhere else, such as Amazon S3. It stays where it is and isn't added to your library.

Alt text you enter for a library image or an upload is saved in the library, so changing it applies wherever that image is used without its own. The shortcode then goes in at the cursor, ready for you to add a width or alignment. Images can't be dragged or pasted into the editor.

![The Insert image popup, listing images from the library with thumbnails](https://raw.githubusercontent.com/DaleFranks/ramasite/main/docs/screenshots/insert-image.png)

You can also type shortcodes yourself:

```
[image:team.jpg]
[image:team.jpg|400|right|Our team at the office]
```

Places an image from the [image library](images.md). After the file name, in any order:

- a **width**: `400` (pixels), `50%`, or `400x300`
- an **alignment**: `left` or `right` (text wraps around the image, except on phones and small tablets, where the image is centered on its own line), or `center`
- **alt text** for screen readers. Without it, the alt text saved in the image library is used. Alt text given here also shows as a tooltip when someone hovers over the image with a mouse, so this is the way to give an image a tooltip: `[image:team.jpg|left|Our team in 2024]`.

A full image address (`https://...`) works too. Ordinary Markdown images, `![Our team](team.jpg)`, also find their file in the library.

### Files

To link to a file, such as a PDF menu or a price list, put the cursor where you want the link and click **Insert file**, beside Insert image. Then:

- choose a file from the **File library**, or **Upload** a new one to it
- give the link its **text**, such as "Our menu". Left empty, the link shows the file's name.
- choose whether to **show the type and size** after the link, such as "Our menu (PDF, 240 KB)"

You can also type shortcodes yourself:

```
[file:menu.pdf]
[file:menu.pdf|Our menu]
[file:menu.pdf|Our menu|details]
```

Links to a file from the [file library](files.md). After the file name, in any order: the link's **text**, and `details` (in lower case) to show the file's type and size after it. The size is looked up each time the page is shown, so it stays right when the file is replaced. Works in pages, posts, cards and modules.

### YouTube videos

```
[youtube:https://www.youtube.com/watch?v=VIDEO_ID]
[youtube:https://youtu.be/VIDEO_ID|640|left]
```

Embeds a video. Optional: a width (`640` or `80%`) and an alignment (`left`, `center` or `right`; centered by default). A left or right video wraps like an image, and takes up 40% of the page's width if you don't set one. On phones and small tablets, it's centered on its own line at its set width, or the full width of the page if none is set, and shrinks if the screen is narrower than its set width.

### Forms

```
[form:contact]
```

Places a form made on the [Forms](forms.md) screen. Works in pages, posts, cards and modules.

### Cards, modules and sliders (pages and posts only)

```
[card:hosting]
[cards: design, development, seo]
[module:call-to-action]
[slider:home-slider]
```

- `[card:slug]` places one [card](cards-and-modules.md); `[cards: a, b, c]` places several side by side. Rows come out even: 2 or 3 cards share a row, 4 make two rows of two, and 5 or more go three to a row.
- `[module:slug]` places a [module](cards-and-modules.md).
- `[slider:slug]` places a [slider](sliders.md).

These can't be placed inside cards or modules.

### In the footer

The footer module has a few shortcodes of its own:

- `[navigation:flat]`: a row of links to the pages in the menu
- `[navigation:multi]`: a column for each page in the menu, with its sub-pages listed under it
- `[curr-year]`: the current year
- `[site-name]`: the site name set on the Theme screen
