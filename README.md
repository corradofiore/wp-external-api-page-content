# External API Page Content

Replace the body of selected WordPress pages with raw HTML or Markdown fetched from an external HTTP API.

[![License: GPL v2 or later](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](LICENSE)

## What it does

A site editor picks a WordPress page, points it at an API endpoint, and the plugin swaps that page's
body for whatever the endpoint returns. The original editor content is never overwritten: it stays in
place as a fallback if the API has never returned a usable response.

Any number of independent page-to-API mappings can be configured. No page purpose or endpoint is
hard-coded.

## Features

- Repeatable page-to-API mappings with an optional administrative label.
- Payload format per mapping: auto-detect, HTML or Markdown.
- Per-mapping cache TTL, plus a last-known-good fallback for outages.
- Per-mapping HTTP request headers (`Header-Name: value`, one per line).
- Bearer authentication through `wp-config.php` constants, so tokens never reach the database.
- Test API button that reports status, Content-Type, size and detected format without touching the cache.
- Filters for request arguments, the KSES allow-list and the final rendered HTML.
- Uninstall routine that removes every option and transient the plugin created.

## Requirements

- WordPress 5.8 or newer
- PHP 7.4 or newer

## Installation

Install through **Plugins → Add New** on your site, or download the release zip and upload it with
**Plugins → Add New → Upload Plugin**.

Then open **Settings → External API Content**, add a mapping, choose a page and enter the endpoint URL.

## Security notes

- Endpoints must be public HTTP/HTTPS URLs and are always fetched with `wp_safe_remote_get()`.
- HTML responses are sanitized with the WordPress KSES rules for post content.
- Markdown is rendered and then sanitized before display.
- Request headers typed into the settings screen live in the options table — use `wp-config.php`
  constants for anything secret.

## Filters

| Filter | Purpose |
| --- | --- |
| `eapc_should_replace_content` | Override the decision to replace a page body. |
| `eapc_request_args` | Change the HTTP request arguments before a fetch. |
| `eapc_allowed_html` | Change the KSES allow-list used for HTML responses. |
| `eapc_markdown_html` | Change the HTML produced from Markdown. |
| `eapc_rendered_content` | Change the final HTML before it is cached and output. |

## Development

This repository is a public mirror of the canonical GitLab repository. The distributed plugin
contains the same unminified source as this repository, so no build step is required to review it.

A local WordPress environment is available through [`@wordpress/env`](https://developer.wordpress.org/block-editor/getting-started/devenv/get-started-with-wp-env/)
and needs Docker plus Node.js:

```sh
npx @wordpress/env start
```

`wp-env` starts WordPress, MariaDB and a WP-CLI container. The plugin directory is mounted into the
container, so edits are picked up immediately.

### Linting

```sh
phpcs          # uses phpcs.xml.dist (WordPress + plugin text domain rules)
```

### Building a release zip

```sh
bin/build-zip.sh
```

The script reads the version from the plugin header, honours `.distignore`, and writes
`build/wp-external-api-page-content-<version>.zip` containing a single top-level plugin folder —
exactly the layout WordPress.org expects.

## Releasing

1. Bump `Version:` in `wp-external-api-page-content.php`.
2. Update `Stable tag:` and the changelog in `readme.txt`.
3. Commit and tag the release.
4. Run `bin/build-zip.sh` and upload the zip.

## License

Released under the [GPLv2 or later](LICENSE).
