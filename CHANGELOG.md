# v1.0.1
## 2026-11-10

1. [](#bugfix)
    * The block no longer shows in the summary of its page in a blog list, nor in the RSS, Atom and JSON feeds of the feed plugin, where it broke the JSON feed
    * The placeholder of the block is replaced in HTML pages only
1. [](#improved)
    * `compatibility: grav: ['2.0']`, the Grav convention (the `grav >=2.2.0` dependency still sets the floor)
    * Tests and CI left out of the release archive (`.gitattributes`)

# v1.0.0
## 2026-11-09

1. [](#new)
    * First public release: Twitch channels set in the Admin, live ones first, as a card grid, a compact list, one featured or a scrolling strip; the player loads only when the reader clicks a live channel
    * `[twitch]` shortcode (shortcode-core): presentation, channels and featured channel per block, several blocks per page, rendered on each request so the live status is never frozen in the page cache
    * English and French texts, following the site language
    * Markup and styles independent of the theme: `--tw-*` custom properties, optional classes passed to the include
