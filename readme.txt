=== External API Page Content ===
Tags: api, markdown, html, remote content
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

Replace the content body of configured WordPress pages with raw HTML or Markdown fetched from external HTTP APIs.

== Description ==

External API Page Content lets you create any number of configurable page-to-API mappings. No page purpose or endpoint is hard-coded.

Each mapping contains:

* an optional administrative label
* a WordPress page
* an external API URL
* payload format: auto-detect, HTML, or Markdown
* its own cache TTL
* optional HTTP request headers, entered one per line
* an optional wp-config.php constant name containing a Bearer token
* enabled/disabled status

The endpoint must return the document directly in the HTTP response body. JSON/XML wrappers are not expected.

Features:

* Repeatable mappings: add or remove as many mappings as needed.
* Uses the WordPress HTTP API (`wp_safe_remote_get`).
* Auto-detects HTML vs Markdown, or lets you force the format per mapping.
* Sanitizes output before rendering.
* Caches successful responses with a configurable TTL per mapping.
* Keeps the last successful API response per mapping as an outage fallback.
* Falls back to the original WordPress editor content if the API has never succeeded.
* Supports global or mapping-specific Bearer authentication without storing secrets in the WordPress database.
* Includes a per-mapping Test API button that shows the raw response payload, HTTP status, content type, size, and detected format without changing the cache.
* Supports per-mapping request headers using a simple `Header-Name: value` textarea.
* Offers filters for advanced request/header customization.

== Installation ==

1. Upload and activate the plugin.
2. Go to Settings -> External API Content.
3. Click Add mapping.
4. Select a WordPress page and enter its API URL.
5. Choose Auto-detect, HTML, or Markdown.
6. Optionally add request headers, one per line in `Header-Name: value` format.
7. Set the mapping's cache TTL and optional Bearer-token constant.
8. Optionally click Test API to inspect the endpoint response before saving.
9. Save settings.

Repeat for as many pages as required.

The original content stored in each WordPress page is not overwritten. It acts as an emergency fallback.

== Upgrading from 1.0 ==

Version 1.1 automatically converts the old fixed-slot settings into ordinary mappings. After migration there is no special handling for any page purpose.

The legacy endpoint-specific constants can continue to work because the migrated rows reference their constant names. You can rename those constants and change the corresponding mapping fields at any time.

== Request headers ==

Each mapping has a Request headers textarea. Enter one header per line:

    X-API-Key: abc123
    X-Tenant: customer-a
    Accept-Language: en

Header names are validated and control characters are removed from values. Malformed lines are ignored. Explicit mapping headers take precedence over the plugin's default headers and over Bearer authentication when the same header name is supplied.

Header values entered here are stored in the WordPress options database. For sensitive Bearer tokens, the wp-config.php constant mechanism remains preferable because it keeps the token value out of plugin settings.

The Test API button uses the request headers currently entered in the mapping, including unsaved changes.

== Authentication ==

For one Bearer token shared by mappings that do not specify their own constant, add this to `wp-config.php`:

`define( 'EAPC_API_BEARER_TOKEN', 'your-token' );`

For a mapping-specific token, define any PHP constant you choose:

`define( 'MY_LEGAL_API_TOKEN', 'mapping-specific-token' );`

Then enter `MY_LEGAL_API_TOKEN` in that mapping's Bearer token constant field.

If a mapping specifies a constant name and that constant is not defined, no Authorization header is sent for that mapping.

For other authentication mechanisms, use the `eapc_request_args` filter from a small site-specific plugin or theme.

Example for an API key header:

    add_filter( 'eapc_request_args', function( $args, $url, $page_id, $mapping_id, $mapping ) {
        if ( 'Policy API' === $mapping['label'] ) {
            $args['headers']['X-API-Key'] = 'replace-me';
        }
        return $args;
    }, 10, 5 );

== Security ==

* URLs are restricted to public HTTP/HTTPS endpoints and fetched with `wp_safe_remote_get()`.
* HTML responses are sanitized using WordPress KSES rules for post content.
* Markdown is converted to HTML and sanitized before display.
* Script tags and other unsafe HTML are removed by default.
* Bearer-token values are not stored by the plugin; only optional constant names are stored.

If you intentionally need additional HTML tags/attributes, use the `eapc_allowed_html` filter.

== Markdown support ==

The bundled dependency-free Markdown renderer is intentionally small and supports the common constructs typically used in content/legal documents:

* headings
* paragraphs
* blockquotes
* horizontal rules
* fenced code blocks
* ordered and unordered lists
* links
* inline code
* bold, emphasis, and strikethrough

It is not a full CommonMark implementation. If your source documents depend on advanced Markdown features such as tables, nested lists, footnotes, or reference-style links, replace/extend the renderer through the `eapc_markdown_html` filter or integrate a full Markdown parser.

== Filters ==

`eapc_should_replace_content`
Controls whether content replacement occurs. Receives the page ID and full mapping.

`eapc_request_args`
Changes WordPress HTTP request arguments before the API request. Receives URL, page ID, stable mapping ID, and full mapping.

`eapc_allowed_html`
Changes the WordPress KSES allowlist for HTML responses.

`eapc_markdown_html`
Changes rendered/sanitized Markdown HTML.

`eapc_rendered_content`
Changes the final HTML before caching/output. Receives the stable mapping ID and full mapping.

== Changelog ==

= 1.3.0 =
* Added per-mapping HTTP request headers textarea.
* Headers use one `Header-Name: value` entry per line and apply to both live fetches and Test API requests.
* Explicit mapping headers override default headers and Bearer authentication when names conflict.
* Header changes are included in cache/fallback identity to avoid reusing content from a different request context.

= 1.2.0 =
* Added a per-mapping Test API button to inspect the raw API payload.
* Test results include HTTP status, Content-Type, response size, and resolved HTML/Markdown format.
* Tests use the current unsaved mapping values and do not populate or alter the normal content cache.
* Non-2xx responses still display their returned payload for diagnostics.

= 1.1.0 =
* Replaced the fixed page slots with an arbitrary repeatable mapping list.
* Added per-mapping labels, cache TTL, enabled status, and configurable Bearer-token constant names.
* Generalized cache/fallback storage and filter context around stable mapping IDs.
* Added automatic migration from version 1.0 settings.

= 1.0.0 =
* Initial release.
