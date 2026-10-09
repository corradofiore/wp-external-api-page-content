# WordPress.org directory assets

These files are **not** part of the plugin and are **not** included in the built zip
(`.distignore` and `bin/build-zip.sh` both exclude this folder).

They belong in the WordPress.org **SVN** repository under a top-level `assets/` directory,
which sits beside `trunk/` and `tags/` — not inside `trunk/`. Upload them there and the
plugin directory picks them up within a few minutes.

```
plugins.svn.wordpress.org/hivekit-external-api-page-content/
├── assets/          <- these files go here
├── tags/
└── trunk/
```

## Expected file names

WordPress.org maps the file names to positions on the plugin page; the names are fixed.

| File | Size | Used for |
| --- | --- | --- |
| `icon-128x128.png` | 128×128 | Plugin icon (search results, admin) |
| `icon-256x256.png` | 256×256 | Retina plugin icon |
| `banner-772x250.png` | 772×250 | Plugin page header |
| `banner-1544x500.png` | 1544×500 | Retina plugin page header |
| `screenshot-1.png` … | any | Ordered by number; captions come from the `== Screenshots ==` section in `readme.txt` |

## Sources

- Icons derived from `~/Downloads/document-and-plug-icon.png` (1254×1254).
- Banners derived from `~/Downloads/document-to-website-data-flow.png` (2172×724), cropped
  from 724 to 703 px tall so the 3.09:1 banner ratio is matched without distortion.
- `screenshot-1.png` derived from `~/Downloads/api-plugin-screenshot.png` (1112×967): a browser
  capture of the settings screen showing placeholder values only, so it contains no site URL,
  endpoint or credential. It documents the 1.3.0 field set; re-shoot after adding the
  Shortcode ID and delivery-mode fields.

## Regenerating

```sh
sips -c 703 2172 ~/Downloads/document-to-website-data-flow.png --out /tmp/banner.png
sips -z 250 772  /tmp/banner.png --out wporg-assets/banner-772x250.png
sips -z 500 1544 /tmp/banner.png --out wporg-assets/banner-1544x500.png
```

Note that banners and icons must **not** use the official WordPress logo or another
project's trademark (plugin directory guideline 17).
