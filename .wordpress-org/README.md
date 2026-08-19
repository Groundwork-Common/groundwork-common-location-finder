# WordPress.org page assets

Not part of the plugin. Everything here is copied to `assets/` in the
WordPress.org Subversion repository by `.github/workflows/deploy.yml`, where it
is used to render the plugin's page — and never downloaded by anyone installing
the plugin. A large banner therefore costs installers nothing.

`.distignore` keeps this directory out of the release zip.

## Expected files

| File | Purpose |
|---|---|
| `screenshot-1.png` … `screenshot-6.png` | Matched by number to the captions under `== Screenshots ==` in `readme.txt`. Caption 1 belongs to `screenshot-1.png`. |
| `banner-1544x500.png` | Page header. `banner-772x250.png` is the low-DPI half. Without one the page shows a grey placeholder. |
| `icon-256x256.png` | Shown in search results and on the Plugins screen. |

## Two things worth knowing

The plugin's page does not exist publicly until the first SVN commit. Commit
code and these assets together and the page appears complete; commit code alone
and it goes live listing six captions with no images behind them.

Captions and files are matched by number only. Reordering the list in
`readme.txt` without renaming the files silently mislabels every screenshot,
and nothing will warn about it.
