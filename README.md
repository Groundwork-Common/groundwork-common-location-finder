# Location Finder

A searchable map-and-list of locations for WordPress, where **you** decide what a
location records.

Only three things are built in: the **name**, the **latitude** and the
**longitude**. Address, opening hours, phone, services, accessibility, whatever
this particular site calls its categories — all of it is defined by an
administrator on *Locations → Fields*, along with the order it renders in.

## Why it works that way

This started as a finder built for one diaper bank. It was good, and it was
unusable anywhere else: the services were a hardcoded list of two, the geocoder
was pinned to a bounding box around Birmingham, the timezone was a literal
`'America/Chicago'`, the default state was `'AL'` in six separate files, and the
stylesheet borrowed twenty-eight custom properties from a sibling plugin.

So the rule here is that nothing may assume. There is no `$location['city']`
anywhere in this codebase. Fields flow through generically — rendered from a
registry, sanitized by a registry, shipped to the browser alongside a schema
that describes them.

## Requirements

WordPress 6.2+, PHP 7.4+. No build step, no Composer, no npm for anything that
ships. Leaflet 1.9.4 is vendored in `assets/leaflet/`.

## Adding it to a page

A block (**Location Finder**) or a shortcode:

```
[location_finder]
[location_finder show_map="no"]
[location_finder height="600"]
```

The shortcode is permanent, not a deprecation path — page builders, widget areas
and classic-editor sites all need one.

## Field types

| Type | Notes |
|---|---|
| Text, Long text, Website, Email, Phone, Number | Searchable; not filterable |
| Yes / no | Filterable as a toggle. False is stored as absence |
| Choice (one) | Filterable; OR within the field |
| Choice (many) | Filterable; **AND** within the field |
| Address | Composite. Drives the Directions link and the editor's address lookup |
| Opening hours | Day/frequency/time slots. Drives "Open now" and "Open today" |
| Temporary closures | Date ranges that suspend the hours |

Address, hours and closures may each exist more than once — "Mailing address"
and "Visiting address" are a real requirement — but exactly one instance of each
is **primary**, and only the primary one drives behavior.

## Things that are deliberate

**Field keys are permanent; labels are not.** Renaming a key would orphan every
value already saved under it, so the Fields screen will not let you. A rename is
a retire-plus-create, which routes through retirement, which never loses data
silently.

**Deleting a field does not delete its data.** It moves to a *Retired fields*
list showing how many locations still hold a value. Erasing it for real needs
the word `DELETE` typed out.

**"Near me" is a sort, not a filter.** There is no radius. A radius produces "no
results" when the nearest match is thirty miles away, which reads as a broken
page rather than as a distant match.

**Nothing time-dependent is precomputed on the server.** The payload is cached
for an hour on a boundary that has nothing to do with midnight, so hour slots and
closure dates ship raw and the browser decides what is open against the site's
clock. Baking in an "open today" flag would keep asserting yesterday's answer for
up to an hour after the date changed.

**Locations have no permalinks.** `public => false`. Giving every location a URL
means shipping hundreds of thin pages that compete with the finder in search
results and that nobody designed. `lfndr_post_type_args` is there for sites that
want them and will provide the templates.

## Theming

The stylesheet ships **layout only** — grid, flex, the map container, the scroll
column, a z-index scale, focus rings. No `font-family`, no absolute font sizes,
no color beyond what a map and a focus outline require. Controls are native
elements (`<button>`, `<input type="search">`, `<select>`, `<details>`, `<dl>`)
so your theme's own rules apply without being asked.

**It references no `--wp--preset--*` variable**, because there is no slug that
can be relied on: a classic theme with no `theme.json` emits zero color presets,
and among block themes the names differ every time (Twenty Twenty-Four has
`accent-1`…`accent-6`, Twenty Twenty-Three has `primary`/`secondary`/`tertiary`).
Instead it uses `currentColor`, the CSS system colors, and a few of its own
custom properties. Only your theme knows its slugs, so your theme does the
mapping:

```css
.lfndr {
  --lfndr-accent:     var(--wp--preset--color--accent-1);
  --lfndr-surface:    var(--wp--preset--color--base);
  --lfndr-on-surface: var(--wp--preset--color--contrast);
  --lfndr-radius:     8px;
  --lfndr-gap:        1.25rem;
  --lfndr-map-height: 560px;
}
```

The block also supports color and typography, so picking a text color in the
editor is inherited by everything inside via `currentColor`.

**Without touching CSS at all**, the same eight properties can be set from
*Locations → Settings → Appearance*: accent, surface, on-surface text, border,
corner radius, spacing, map height and panel height. Every field accepts a
plain CSS value — a hex code, a named color, a length with its unit, or a
`var(--wp--preset--color--…)` reference, which is exactly the snippet above
typed into a text box instead of a stylesheet. Left blank, a field behaves as
if the setting never existed; nothing is required for the finder to look
reasonable out of the box. Values are printed into a scoped `<style>` block
attached to the plugin's own stylesheet handle, so — like every other asset —
they only load on a page that actually has a finder, and a theme's own CSS,
loaded after, can still override them if it wants the final say.

One honest limitation: custom properties cannot be used in `@media` width
queries. The 860px breakpoint is a literal, changed with
`add_filter( 'lfndr_breakpoint', … )` rather than a variable that would look
adjustable and do nothing.

## Hooks

| Hook | Purpose |
|---|---|
| `lfndr_field_types` | Register a field type (see below) |
| `lfndr_field_label` | Translate an admin-entered label (WPML/Polylang) |
| `lfndr_available_facets` | Adjust which filters render |
| `lfndr_post_type_args` | Change the post type registration |
| `lfndr_load_assets` | Force assets onto a page the gate cannot detect |
| `lfndr_cache_ttl` | Payload cache lifetime, default one hour |
| `lfndr_tile_url`, `lfndr_tile_attribution` | Map tiles |
| `lfndr_schema_migrations` | Migrate a schema between versions |
| `lfndr_schema_saved` | React to a schema change |

### A custom field type

Seven callables, all pure except the renderer:

```php
add_filter( 'lfndr_field_types', function ( array $types ): array {
    $types['rating'] = array(
        'label'        => 'Rating',
        'render_admin' => 'my_render_rating',  // (array $field, $value, string $name): void
        'sanitize'     => 'my_sanitize_rating',// ($raw, array $field): mixed
        'is_empty'     => 'my_rating_is_empty',// ($value, array $field): bool
        'to_payload'   => 'lfndr_payload_scalar',
        'search_text'  => null,                // null = cannot be searched
        'facet_tokens' => null,                // null = cannot be filtered
        'schema_form'  => 'my_rating_settings',
        'js'           => 'rating',
    );
    return $types;
} );
```

`facet_tokens => null` is what grays out "Offer as a filter" on the Fields
screen — the UI is derived from the registry, so a new type gets correct
controls for free.

Then a front-end renderer, in a script that depends on `lfndr-finder`:

```js
window.LocationFinder.renderers.rating = function ( value, field, ctx ) {
    return document.createTextNode( '★'.repeat( Number( value ) ) ); // Node or null
};
```

Renderers build DOM with `createElement`/`textContent`, never `innerHTML`. With
an admin-defined schema, string concatenation would put an escaping decision at
around a hundred call sites, each one a place a missed call becomes stored XSS
injectable by anybody who can edit a location.

## External services

Two, both disclosed because they must be:

- **OpenStreetMap tiles** (`tile.openstreetmap.org`), requested by visitors'
  browsers. Donated infrastructure with a
  [usage policy](https://operations.osmfoundation.org/policies/tiles/). A busy
  site should point `lfndr_tile_url` at a paid provider or self-host.
- **Nominatim** (`nominatim.openstreetmap.org`), requested by the *server*, only
  when an editor uses the address lookup. Rate limited to one request per second
  per user, and the response is whitelist-mapped rather than forwarded.

Nominatim's front end returns a bare `403` for any `User-Agent` containing an
email address — verified against the live service. The contact goes in the
documented `email` query parameter instead. Putting it in the User-Agent looks
like following the policy and silently breaks every lookup.

## Tests

Pure logic, no WordPress, no database, under a second:

```bash
curl -sLO https://phar.phpunit.de/phpunit-11.phar
php phpunit-11.phar
```

`tests/bootstrap.php` stubs the dozen WordPress string helpers the pure code
touches. What genuinely needs a running stack lives in `tests/integration/` and
runs under wp-env:

```bash
npx @wordpress/env start
npx @wordpress/env run cli -- wp eval-file wp-content/plugins/location-finder/tests/integration/save-roundtrip.php
npx @wordpress/env run cli -- wp eval-file wp-content/plugins/location-finder/tests/integration/address.php
npx @wordpress/env run cli -- wp eval-file wp-content/plugins/location-finder/tests/integration/payload.php
```

Those three cover the things stubs would only prove about themselves: how
`update_post_meta` serializes a bool, what an emptied checkbox actually posts,
and that the query count stays flat as locations are added.

## Still to come

CSV export, schema import/export, an optional opinionated skin, `readme.txt`
plus a translation template, and a Playground demo blueprint.
