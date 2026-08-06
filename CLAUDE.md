# Working in this repository

A WordPress plugin: a searchable map-and-list of locations, where the site
decides what a location records.

Read `README.md` first. The founding rule is in the main file header and it
governs everything else:

> Nothing may assume. There is no `$location['city']` anywhere in this codebase.

Three fields are built in — name, latitude, longitude. Everything else is
defined by an admin on *Locations → Fields* and flows generically through the
type registry in `inc/field-types.php`. **Do not introduce a hardcoded field
name, region, state or timezone.** That is not a preference; it is the reason
the plugin exists.

## The shape of the code

Procedural PHP, prefix `lfndr_`, no classes, no namespaces, no autoloader.
Eighteen files in `inc/`, required from the bootstrap with
`if ( ! function_exists( … ) )` guards.

Two ordering constraints, both documented in the main file:

- `schema.php` must precede everything that reads a schema, and
  `field-types.php` must precede `meta-box.php`. **The require list is not
  alphabetical. Do not sort it.**
- **Nothing is wrapped in `is_admin()`.** That answers "is this a wp-admin
  request", and WP-CLI, cron and the REST API are none of those. Wrapping the
  requires produces functions that exist on the screen you tested and are fatally
  undefined under `wp eval`.

**No build step.** No Composer, no npm, no Sass, nothing compiled. Leaflet 1.9.4
is vendored in `assets/leaflet/`. Consequences:

- `blocks/finder/edit.asset.php` is **hand-written**, because `@wordpress/scripts`
  would normally generate it. Adding a `wp.*` import to `edit.js` without adding
  it there is the one way that file goes wrong.
- Leaflet has no lockfile. A monthly workflow asks npm for the latest version and
  opens an issue rather than a PR, because a bot cannot verify the upgrade. **Leaflet 2.x
  is ESM-only and drops the UMD build; this plugin registers Leaflet as a classic
  script and uses `window.L` throughout, so 2.x is a migration, not an update.**

## Verifying a change

```bash
curl -sLO https://phar.phpunit.de/phpunit-11.phar
php phpunit-11.phar
```

`phpunit.xml.dist` sets `failOnWarning` and `failOnDeprecation`. `tests/bootstrap.php`
stubs the WordPress functions it needs plus an in-memory option store, so the
suite needs no database and no WordPress checkout.

Integration, against a real MySQL:

```bash
npx @wordpress/env start
npx @wordpress/env run cli -- wp eval-file \
  wp-content/plugins/groundwork-common-location-finder/tests/integration/save-roundtrip.php
```

Also `address.php` and `payload.php` in the same directory.

> The docblocks inside `tests/integration/*.php` and the commands in `README.md`
> still say `wp-content/plugins/location-finder/`. That path is stale — the
> directory was renamed to match the plugin name and `.wp-env.json` mounts `["."]`,
> so it resolves under the repo's own basename. Use the path above.

**Nothing runs any of this in CI.** `.github/workflows/` holds only `deploy.yml`
and the Leaflet version check. No workflow runs PHPUnit, phpcs or Plugin Check.
If you do not run the suite yourself, nothing will.

There is no phpcs ruleset in the repo, but the code is written against WordPress
Coding Standards and carries 25-plus `phpcs:ignore` annotations. **Never add one
without a `--` reason after it**; every existing one explains itself.

## The rules that are load-bearing

- **Renderers build DOM with `createElement`/`textContent`, never `innerHTML`.**
  With an admin-defined schema, string concatenation puts an escaping decision at
  around a hundred call sites, and one missed call is stored XSS injectable by
  anybody who can edit a location.
- **Field keys are permanent.** Renaming is retire-then-create. Deleting retires;
  real erasure requires typing `DELETE`. A retired key must be restored rather
  than recreated, or the new field silently picks up the old data.
- **Bad coordinates are emptied, not clamped.** A latitude of 91 is a typo or a
  swapped pair; clamping it to 90 silently places the location at the north pole,
  and a plausible-looking value is much harder to notice than a blank one.
- **"Near me" is a sort, not a filter.** There is no radius.
- **The stylesheet references no `--wp--preset--*` variable**, because no theme
  slug can be relied on.
- **`uninstall.php` never deletes location posts or their field data**, even when
  the destructive option is armed. Those are the site's records, not the
  plugin's.
- **Activation does not call `flush_rewrite_rules()`.** On the activating request
  the post type has not been registered yet — `init` already fired — so a flush
  there writes rules that exclude ours, and the 404s look like a permalink bug.
- **`lfndr_settings_cache()` and `lfndr_schema_cache()` are functions, not
  statics,** because a writer needs a way to invalidate a reader's cache and PHP
  cannot reach another function's static variable.

## Releasing

`git tag` plus a GitHub Release. `deploy.yml` refuses unless the tag matches all
three of the plugin header `Version:`, `readme.txt` `Stable tag:`, and
`LFNDR_VERSION` — and `VersionTest` checks the same agreement locally, plus the
changelog and upgrade notice. **Bump them together or the release is blocked.**

`LeafletVersionTest` asserts the `t.version=` string inside the vendored bundle
matches the literal in `inc/enqueue.php` — that literal is the cache buster, and
the only thing that makes a browser holding the old bundle fetch the new one.

Screenshot captions in `readme.txt` are matched to files **by number only**.
Reordering the list without renaming the files silently mislabels every
screenshot, and nothing warns about it.

## A gap worth knowing

`tests/FormWiringTest.php` exists because the rest of the suite calls sanitizers
with arrays it builds itself. That proves the sanitizer works on the payload the
*test* invents and says nothing about whether the form sends that payload. One
commit here is titled "185 tests passed with three controls saving nowhere."

When you add a control, add the wiring assertion too.

## Where work is tracked

GitHub Issues on this repo. Run `gh issue list` before starting and file what you
find there. The repo is private, so write plainly.
