# Cards and Modules

Both are pieces of content written once and placed in pages with a shortcode, so they can be reused and changed in one place.

Use a **card** for a small box of text, which can sit in columns with other cards. Use a **module** for a whole section of a page, including a layout written in HTML; it always takes the full width of the page and can't sit in columns.

## Cards

A card is a small box, such as a service, a price plan or a call to action. Cards are often shown side by side.

- **Name**: for finding it in the admin.
- **Slug**: used in the shortcode: `[card:hosting]` for one card, or `[cards: design, development, seo]` for several in a row. While a page or post places the card, it can't be deleted and its slug can't change; the message says where it's used, so those can be changed first. The same goes for modules.
- **Style**: plain, or a colored panel: **Dark**, **Light** or **Primary color**. The panel colors are set on the Theme screen's **Panel styles** tab.
- **Text alignment**: **Left**, **Centered** or **Right**. Left unset, the card uses its style's alignment: **Dark** and **Primary color** cards are centered, **Light** and plain cards are left-aligned.
- **Featured**: highlights the card with a colored border and a "Featured" ribbon.
- **Content**: Markdown. Images, videos and forms can be used inside a card.

## Modules

A module is a larger section, such as a call-to-action band or a contact section. Place one in a page with `[module:slug]`. On a page with the **Landing** layout, modules stretch across the whole window as bands.

Modules have a name, slug, style and content, like cards. They also have:

- **Background image**: a picture from the [image library](images.md) behind the module's text, on any page. It always fills the module and is cropped to fit. The text color comes from the module's style. The list shows the 50 newest images; type part of a name or alt text to find older ones. A search shows up to 50 matches, so type more to narrow it. An image used this way can't be deleted from the library until it's removed from the module.
- **Top edge** and **Bottom edge**: None (the starting choice), Slant left, Slant right or Wave, each set separately. They only take effect on the **Landing** layout. The shape cuts into the band, the page color shows through the part cut away, and a space of page color is left on that side. A slant left rises toward the left of the window, and a slant right toward the right.

The footer doesn't have these settings.

## The footer

The footer is a module too, shown at the bottom of every page. It's always in the Modules list and can't be deleted or renamed. Its color is set on the Theme screen's **Footer** tab, which also has a link straight to it.

It can use these shortcodes:

- `[navigation:flat]`: a row of links to the pages in the menu
- `[navigation:multi]`: a column for each page in the menu, with its sub-pages under it
- `[curr-year]`: the current year
- `[site-name]`: the site name
