# `raw` — Nextcloud raw file server

**`raw`** serves files **as-is** so you can link directly to the file itself (i.e. without any of Nextcloud’s UI). This makes it easy to host static web pages, images, or other assets and embed/link them elsewhere.

**Design goals**

* **Minimal**: deliver bytes, not UI.
* **Fast**: keep server work low (good for assets).
* **Quiet failures**: plain 404 Not found (text/plain) for invalid/missing public shares (no Nextcloud HTML error pages), ideal for asset fetches.
* **Privacy-friendly**: **cookie-free responses** (best effort).
* **Allowlist-gated:** public `raw` access is opt-in — only explicitly allowlisted public share tokens (or wildcard matches) are served.
* **Secure by default**: strict CSP with optional per-scope overrides. *)
* **Efficient validators**: for `HEAD` and `304 Not Modified`, `raw` avoids reading file contents whenever possible (no unnecessary `getContent()`). It prefers “fast” validators (mtime+size) and only performs content-based MIME sniffing when it is actually needed for a final `200` response.
* **MIME sniffing**: When content-based detection is needed, `raw` only sniffs a small prefix (currently 32 KiB) not the full file.
* **Streaming by default**: for normal `GET` (`200`) responses, `raw` streams the body whenever possible instead of loading the entire file into memory, improving throughput under load.

*) For security and privacy the content is served with a [Content-Security-Policy][] (CSP) header. You can configure CSP rules in detail via Nextcloud’s system [`config/{raw.}config.php`](#keep-raw-settings-in-a-dedicated-config-file) key `raw_csp`. See [Content Security Policy (raw_csp)](#content-security-policy-raw_csp) below.

---

## Table of contents

* [Quickstart](#quickstart)

* [URL forms](#url-forms)

  * [Public shares](#public-shares)
  * [Private user files](#private-user-files)
  * [Root aliases (`/raw` and `/rss`)](#root-aliases-raw-and-rss)

* [Access control: token allowlist](#access-control-token-allowlist)

  * [`allowed_raw_tokens`](#allowed_raw_tokens)
  * [`allowed_raw_token_wildcards`](#allowed_raw_token_wildcards)

* [Content Security Policy (raw_csp)](#content-security-policy-raw_csp)

  * [Matching priority](#matching-priority)
  * [Policy formats accepted](#policy-formats-accepted)
  * [Allowed directives](#allowed-directives)
  * [Example PHP `config/{raw.}config.php` snippets](#example-php-configrawconfigphp-snippets)
  * [Testing](#testing)

* [Optional system-level tuning](#optional-system-level-tuning)

  * [Cache-Control](#cache-control)
  * [Webserver offload](#webserver-offload)

    * [Offload debug header](#offload-debug-header)

* [HTTP behavior & performance](#http-behavior--performance)

  * [Cookie-free responses](#cookie-free-responses)
  * [Caching: ETags and Last-Modified](#caching-etags-and-last-modified)
  * [Directory handling (`index.html`)](#directory-handling-indexhtml)
  * [HEAD requests](#head-requests)
  * [Plain 404 for invalid public shares](#plain-404-for-invalid-public-shares)

* [Notes & best practices](#notes--best-practices)

* [Installation](#installation)

  * [Updating](#updating)

---

## Quickstart

1. [Install/enable the app.](#installation)
2. Create a **public share link** (token) for a file or folder.
3. Open the `raw` URL:

   * `https://my-nextcloud/apps/raw/s/<token>`
   * and for folders: `https://my-nextcloud/apps/raw/s/<token>/<path/to/file>`
4. Configure which share tokens are allowed:

   * `allowed_raw_tokens` and/or `allowed_raw_token_wildcards`
5. (Optional) Configure CSP policies via `raw_csp`.

---

## URL forms

### Public shares

If the share link is:

```
https://my-nextcloud/s/aBc123DeF456xyZ
```

then this app will serve the `raw` file at:

```
https://my-nextcloud/apps/raw/s/aBc123DeF456xyZ
```

If the share is a folder, files within it are accessible as:

```
https://my-nextcloud/apps/raw/s/aBc123DeF456xyZ/path/to/file
```

The `/s/` can also be omitted:

```
https://my-nextcloud/apps/raw/aBc123DeF456xyZ/path/to/file
```

also works.

### Private user files

A user can access their own private files. For example, a file named `test.html` in anansi’s Documents folder would be available at:

```
https://my-nextcloud/apps/raw/u/anansi/Documents/test.html
```

The `/u/` can **not** be omitted, so:

```
https://my-nextcloud/apps/raw/anansi/Documents/test.html
```

does **not** work.

### Root aliases (`/raw` and `/rss`)

If your Nextcloud is configured to allow `raw` in the root namespace *), additional public URL forms become available:

- `/raw/{token}`
- `/raw/{token}/{path}`

Legacy compatibility aliases:
- `/raw/s/{token}`
- `/raw/s/{token}/{path}

Special namespace shortcut:
- `/rss`            (behaves like `/raw/rss`)
- `/rss/{path}`     (behaves like `/raw/rss/{path}`)

> [!NOTE]
> `/rss` and `/rss/{path}` are convenience shortcuts that internally behave exactly like `/raw/rss` and `/raw/rss/{path}`. They do not bypass the [token allowlist](#access-control-token-allowlist) — the underlying share token is still `rss`.

> [!IMPORTANT]
> *) These root aliases require a core configuration allowlist entry (Nextcloud `rootUrlApps` including `raw`) in the file *lib/private/AppFramework/Routing/RouteParser.php*

> [!NOTE]
> If `rootUrlApps` is not configured (or blocked by your setup), the canonical and always-available URLs remain under `/apps/raw/...`.

---

## Access control: token allowlist

The app uses a **token allowlist** to control which public share tokens are allowed to access `raw` content.

> [!IMPORTANT]
> **Only explicitly allowed tokens (or tokens matching configured wildcards) are served by `raw`.**

> [!NOTE]
> The wildcard matching applies to the **share token** (the public link id), not to file names or paths.

One or both of the following arrays in [`config/{raw.}config.php`](#keep-raw-settings-in-a-dedicated-config-file) must be defined to configure token-based allowlist restrictions
(otherwise all public `raw` requests will return `Not found`):

### `allowed_raw_tokens`

An array of explicitly allowed tokens. These tokens must exactly match the share token used in `raw` links.

### `allowed_raw_token_wildcards`

An array of wildcard patterns (`*`) matched against the share token. Wildcards are translated into regular expressions for dynamic validation.

#### Example configuration

```php
<?php
$CONFIG = array (
// -
  'allowed_raw_tokens' =>
  array (
    0 => 'scripts',
    1 => 'aBc123DeF456xyZ',
    2 => 'includes',
    3 => 'html',
  ),
  'allowed_raw_token_wildcards' =>
  array (
    0 => '*suffix',
    1 => 'prefix*',
    2 => 'prefix*suffix',
    3 => '*infix*',
    4 => 'prefix*infix*',
  ),
// -
);
```

In this configuration:

* Tokens such as `scripts`, `aBc123DeF456xyZ`, `includes`, and `html` are explicitly allowed.
* Wildcards match the share token and can be used as:

  * suffix: `*_json` → `data_json`
  * prefix: `nc-*` → `nc-assets`
  * infix: `*holiday_img*` → `2026-02-10-holiday_img.jpg`, `2026-02-12-holiday_img.png`
  * combined: `site-*_asset_*` → `site-example.com_asset_script.js`, `site-other.example.com_asset_style.css`

### Usage with human-readable tokens

In the example above, some share links were created as `custom public links`. Generating human-readable tokens (instead of randomly generated ones) makes links more meaningful and easier to manage.

For example:

* Instead of a random token like `aBc123DeF456xyZ`, you can use a meaningful token such as `html`, `javascript` or `data_json` for shared directories, or prepend prefixes, append suffixes or include infixes to enable them as wildcard.

This approach enhances both usability and security by allowing administrators to control access to `raw` links more effectively while keeping token names meaningful and consistent.

---

## Content Security Policy (raw_csp)

`raw` supports configurable Content-Security-Policy (CSP) rules via the Nextcloud system config key `raw_csp`. The CSP config lets admins tune how `raw` serves files from different paths, file extensions, or MIME types — and optionally per share token.

> [!NOTE]
> If `raw_csp` is not defined, `raw` falls back to this safe, very restrictive CSP:
>
> ```
> "sandbox; default-src 'none'; style-src data: 'unsafe-inline'; img-src data:; media-src data:; font-src data:; frame-src data:"
> ```
>
> This fallback is implemented hardcoded inside of the app (not in `config.php`).

### Matching priority

When deciding which CSP to send, `raw` evaluates selectors in this order:

* `token` (optional) — exact match for a public share token (the share id that appears in public URLs).
* `path_prefix` — longest matching prefix. Supports absolute prefixes (starting with `/apps/raw`) and relative prefixes (matched against the path after `/apps/raw/...`).
* `path_contains` — substring match. The manager checks both the full request path and the path after `/apps/raw` so public and private URLs are covered.
* `extension` — file extension match (e.g. `html`, `json`).
* `mimetype` — MIME type match (e.g. `text/html`, `application/json`).
* hard-coded fallback (if nothing matches).

> [!NOTE]
> `token` is the share token assigned by Nextcloud for public shares. Private user paths (`/apps/raw/u/...`) do not carry a share token, therefore `token` cannot match on private URLs.

### Policy formats accepted

A policy value for a selector may be one of:

* *String* — a full, single-line CSP header value (passed through and sanitized).
* *Indexed array* — list of directive strings; entries are joined with `;`.
* *Associative array* (recommended) — `'directive' => sources`. `sources` may be a string (space separated) or an array of strings. The manager normalizes values, deduplicates and outputs a canonical single-line header.

### Allowed directives

Allowed directive names are deliberately limited (to keep policies sane and safe):

* Fetch directives:

  * Fallbacks: [`default-src`][], [`script-src`][], [`style-src`][], [`child-src`][]
  * Common: [`connect-src`][], [`font-src`][], [`frame-src`][], [`img-src`][], [`manifest-src`][], [`media-src`][], [`object-src`][], [`worker-src`][]
* Document directives: [`base-uri`][], [`sandbox`][]
* Navigation directives: [`form-action`][], [`frame-ancestors`][]
* Other directives: [`upgrade-insecure-requests`][]

Unknown/unsupported directives are ignored by the manager.

### Example PHP `config/{raw.}config.php` snippets

1. Extension rule — make all `.html` permissive

```php
<?php
$CONFIG = array (
// -
  // Example: make all .html files more permissive
  'raw_csp' =>
  array(
    'extension' =>
    array(
      'html' =>
      array(
        'default-src' => ["'self'"],
        'script-src'  => ["'self'", "'unsafe-inline'"],
        'img-src'     => ["'self'", "data:"],
        'media-src'   => ["data:"],
        'style-src'   => ["'self'", "'unsafe-inline'"],
        'font-src'    => ["data:"],
        'frame-src'   => ["'none'"],
      ),
    ),
  ),
// -
);
```

2. Path prefix — relative and absolute

```php
<?php
$CONFIG = array (
// -
  // Example: an absolute prefix and a relative prefix
  'raw_csp' =>
  array(
    'path_prefix' =>
    // absolute prefix: matches full URI starting at /apps/raw/...
    array(
      '/apps/raw/s/special-html/' =>
      array(
        'default-src' => ["'self'"],
        'script-src'  => ["'self'"],
      ),
      // relative prefix: matched against the path AFTER /apps/raw[/s/{token}] or /apps/raw/u/{user}/
      'html/' =>
      array(
        'default-src' => ["'self'"],
        'script-src'  => ["'self'", "'unsafe-inline'"],
        'img-src'     => ["'self'", "data:"],
      ),
    ),
  ),
// -
);
```

3. Path contains — substring match (public + private)

```php
<?php
$CONFIG = array (
// -
  // Example: apply when '/html/' appears anywhere in the path
  'raw_csp' =>
  array(
    'path_contains' =>
    array(
      '/html/' =>
      array(
        'default-src' => ["'self'"],
        'script-src'  => ["'self'"],
        'img-src'     => ["'self'", "data:"],
        'style-src'   => ["'self'", "'unsafe-inline'"],
      ),
    ),
  ),
// -
);
```

4. Token — per share-token policy (optional)

```php
<?php
$CONFIG = array (
// -
  // Example: apply a policy only for the public share token 'abc123'
  // This only applies when the public URL contains the token 'abc123'.
  'raw_csp' =>
  array(
    'token' =>
    array(
      'abc123' =>
      array(
        'default-src' => ["'self'"],
        'img-src'     => ["'self'", "data:"],
      ),
    ),
  ),
// -
);
```

5. Combined example

```php
<?php
$CONFIG = array (
// -
  'raw_csp' =>
  array(
    'path_prefix' =>
    array(
      'html/' =>
      array(
        'default-src' => ["'self'"],
        'script-src'  => ["'self'", "'unsafe-inline'"],
      ),
    ),
    'path_contains' =>
    array(
      '/public/static/' =>
      array(
        'default-src' => ["'self'"],
        'img-src'     => ["'self'", "data:"],
      ),
    ),
    'extension' =>
    array(
      'json' =>
      array(
        'default-src' => ["'none'"],
        'img-src'     => ["data:"],
      ),
    ),
  ),
// -
);
```

**Important note about `path_contains` matching:**

If a pattern starts with a slash (for example '`/html/`'), the pattern is used verbatim as a substring search. '`/html/`' only matches when the exact sequence "`/html/`" appears in the request path (use this to target a folder segment precisely).

If a pattern does not start with a slash (for example '`html`'), the pattern is used as a plain substring (no leading slash is added). '`html`' therefore matches anywhere the characters `html` appear — e.g. `/some_html_text/`, `/some-html-data/`, `/htmlfile` and `/html/`.

Consequence: `some-html-data` will match the pattern '`html`' but will not match '`/html/`'.

Recommendation: use '`/folder/`' when you need to match a folder segment exactly; use a plain token like '`foo`' when you intentionally want a broad substring match.

The manager checks `path_contains` against both the full request path and the path portion after `/apps/raw`, so public and private URLs are covered.

### Testing

After you update [`config/{raw.}config.php`](#keep-raw-settings-in-a-dedicated-config-file) (or deploy changes), test with curl:

- **Public share (token) URL**:
  ```sh
  curl -I 'https://your-instance.example/apps/raw/s/html/calc.html'
  ```

- **Private user URL**:
  ```sh
  curl -I 'https://your-instance.example/apps/raw/u/alice/Documents/html/calc.html'
  ```

Inspect the `Content-Security-Policy:` response header. If you do not get the expected policy:

* make sure the selector matches your URL form (token vs path vs extension),
* check `nextcloud.log` for exceptions from `CspManager` or syntax errors in your config array,
* remember that `token` only matches explicit share tokens.

---

## Optional system-level tuning

This app supports optional system-level tuning via Nextcloud [`config/{raw.}config.php`](#keep-raw-settings-in-a-dedicated-config-file) system values.

### Cache-Control

Public responses use a configurable Cache-Control header:

- `raw_cache_public_max_age` (int seconds, default: 300)
- `raw_cache_public_stale_while_revalidate` (int seconds, default: 30; 0 disables)
- `raw_cache_public_stale_if_error` (int seconds, default: 86400; 0 disables)

Private `raw` URLs (`/apps/raw/u/...`) default to `private, max-age=0`

Optionally enforce no-store for private URLs:
- `raw_cache_private_no_store` (bool, default: false)

> [!NOTE]
> `304 Not Modified` responses apply the same Cache-Control policy (public vs. private) as normal `200` responses, so caches behave consistently across conditional requests.

### Webserver offload

For large files you can optionally let the webserver send the file body (PHP returns early):

- `raw_sendfile_backend` (off|apache|nginx default: off)
- `raw_sendfile_allow_private` (bool, default: false) *)
- `raw_sendfile_min_size_mb` (int, default: 0) **)
- `raw_sendfile_nginx_prefix` (string, default: /_raw_sendfile)

> [!NOTE]
> *) By default, offload is disabled for private raw URLs (`/apps/raw/u/...`) to keep authenticated endpoints conservative by default. If you want the webserver to offload private raw responses too, enable `raw_sendfile_allow_private`.

> [!NOTE]
> **) If `raw_sendfile_min_size_mb` is set, offload is only attempted when the file size is known and meets the threshold. If the size cannot be determined (e.g. certain storage backends), offload is skipped.

**Prerequisites** (webserver configuration required):

- **Apache**:
  - Requires `mod_xsendfile` *) (or an equivalent X-Sendfile implementation) to be installed and enabled.
  - Enable it and configure the allowed path(s) to include your Nextcloud datadirectory:
    ```apacheconf
    XSendFile On
    # Use the *real* Nextcloud datadirectory from `config/config.php` -> 'datadirectory'
    XSendFilePath /path/to/nextcloud/data
    ```
> [!NOTE]
> *) Module naming varies by distribution; the key requirement is that your Apache build supports `X-Sendfile` and that the module is enabled for the vhost serving Nextcloud.

- **Nginx**:
  - Uses `X-Accel-Redirect` (built into nginx, no extra module needed).
  - Requires an `internal` location that maps the configured prefix (default `/_raw_sendfile`) to Nextcloud’s data directory
    via `alias` (and must ensure access is restricted to internal redirects only).
  - Example (must match your `raw_sendfile_nginx_prefix` and Nextcloud datadirectory):
    ```nginx
    location /_raw_sendfile/ {
        internal;
        alias /path/to/nextcloud/data/;
    }
    ```
> [!TIP]
> `raw` builds the Nginx `X-Accel-Redirect` target by stripping the resolved (`realpath`) Nextcloud `datadirectory` prefix from the local file path. Ensure your Nginx `alias` uses the same resolved datadirectory path (and includes a trailing `/`). If `datadirectory` is a symlink but Nginx points to the symlink path (or vice versa), the mapping can mismatch and offload will be skipped.

example offload configuration in [`config/{raw.}config.php`](#keep-raw-settings-in-a-dedicated-config-file) (for apache2):
```php
<?php
$CONFIG = array (
// -
  // Private `raw` caching
  'raw_cache_private_no_store' => false, // true = Never save in browser

  // apache2
  'raw_sendfile_backend' => 'apache',
/*
  // nginx
  'raw_sendfile_backend' => 'nginx',
  'raw_sendfile_nginx_prefix' => '/_raw_sendfile',
*/

  // allow offload also for /apps/raw/u/... (default false)
  'raw_sendfile_allow_private' => false,

  // only offload for files >= X MB (default 0 = no threshold)
  'raw_sendfile_min_size_mb' => 5,
// -
);
```

Security notes:
- Offload is only attempted for files that can be resolved to a local filesystem path and are located inside Nextcloud's datadirectory.

#### Offload debug header

To debug whether offload/streaming was used, send this request header:

- `X-Raw-Offload-Debug: 1`

The response may include:
- `X-Raw-Offload: <status>; reason=<reason>`

> [!NOTE]
> When offload is active and actually used, the response may include an `X-Raw-Offload` header (e.g. `apache-xsendfile` / `nginx-x-accel`) even without debug enabled.
> If you send `X-Raw-Offload-Debug: 1`, the app adds `reason=...` and can also emit a “not offloaded” reason, which is useful to validate your config and thresholds.


---

## HTTP behavior & performance

### Cookie-free responses

`raw` intentionally aims to be **cookie-free**. It will best-effort prevent `Set-Cookie` from being emitted for `raw` responses (e.g. by closing any active session, disabling session cookies for the remainder of the request, and removing already queued `Set-Cookie` headers).

This keeps endpoints “naked” for asset serving and reduces overhead. (Best effort: a reverse proxy could still add cookies afterwards.)

### Caching: ETags and Last-Modified

`raw` supports conditional requests (cache validation) using ETags together with the `If-None-Match` header and also supports `Last-Modified` / `If-Modified-Since` semantics.

> [!NOTE]
> `raw` prefers “fast” validators (mtime + size) for ETag generation and only falls back to a content hash when needed.

* **ETag / If-None-Match**: The server sends an `ETag` header identifying the current representation of the file. If the client sends `If-None-Match: "<ETag>"` and the value matches, the server responds with `304 Not Modified` and no response body. The wildcard `If-None-Match: *` is also supported.
* **Last-Modified / If-Modified-Since**: When the server can read file modification time (mtime) it sets a `Last-Modified` header. The server will honor `If-Modified-Since` when `If-None-Match` is not present. If the client date is equal to or newer than the file mtime, the server responds with `304 Not Modified`.
* **Unix timestamp convenience**: For convenience, `If-Modified-Since` accepts either an RFC-style HTTP-date (recommended) **or** a plain Unix timestamp (seconds). The server will trim optional quotes. RFC-style dates are the standard and should be preferred for interoperability.

Examples:

- Get file and see headers + body (returns ETag and Last-Modified):

   ```bash
   curl -i 'https://your.nextcloud/apps/raw/.../file.ext'
   ```

- Conditional GET using ETag (replace `<ETag>` with the ETag value returned by the server, should return 304 if it matches):

   ```bash
   curl -i -H 'If-None-Match: "<ETag>"' 'https://your.nextcloud/apps/raw/.../file.ext'
   ```

- Conditional GET using HTTP-date:

   ```bash
   curl -i -H 'If-Modified-Since: "Sun, 25 May 2025 21:40:02 GMT"' 'https://your.nextcloud/apps/raw/.../file.ext'
   ```

- Conditional GET using Unix timestamp (convenience):

   ```bash
   curl -i -H 'If-Modified-Since: "1748209203"' 'https://your.nextcloud/apps/raw/.../file.ext'
   ```

- The wildcard `If-None-Match: *` is also supported (it matches any existing representation) and will return a 304 if the resource exists:

   ```bash
   curl -i -H 'If-None-Match: *' 'https://your.nextcloud/apps/raw/.../file.ext'
   ```

### Directory handling (`index.html`)

If the requested node is a directory, `raw` attempts to serve `index.html` from that directory.

### HEAD requests

`raw` supports `HEAD` requests (headers only, no response body).

### Plain 404 for invalid public shares

For public endpoints, `raw` returns a minimal `text/plain` **404 Not found** response for disallowed tokens, missing shares, and missing paths. This avoids rendering large HTML error pages and keeps `raw` endpoints lightweight.

---

## Notes & best practices

* Review and update `allowed_raw_tokens` and `allowed_raw_token_wildcards` periodically to align with your security requirements.
* Use meaningful share tokens wherever possible for improved manageability.
* Validate CSP rules and token configurations in a test environment before applying them in production.
* Prefer `extension` or `path-based` matching for predictable results. `path_contains` with `'/html/'` is usually the safest way to target a folder named `html`.
* Avoid `script-src 'unsafe-inline'` unless absolutely necessary. When you need inline scripts, prefer nonces or restrictive policies.
* Keep the `token` selector only if you want per-share (per-token) policies. If you do not need that granularity, it is safe to remove `token` and rely on path/extension/mimetype rules.
* The manager normalizes directives and removes duplicates; unknown directives are ignored (no crash but check logs).
### Keep `raw` settings in a dedicated config file:
  * Nextcloud can load settings from multiple files in `config/.` For `raw`, it’s recommended to keep all `raw` related directives like `allowed_raw_tokens`, `allowed_raw_token_wildcards`, `raw_csp` etc. in a dedicated **`config/raw.config.php`** (any *.config.php in config/ is loaded and overrides config/config.php).
  * This keeps raw-specific security settings isolated, avoids accidental clutter in config.php, and plays nicely with config management.
  * **Gotcha:** Nextcloud can consolidate config values into `config/config.php`. Don’t rely on `occ` for `raw` settings if `config/raw.config.php` exists — `raw.config.php` has precedence and will override later.

---

## Installation

1) Clone this repo into your Nextcloud installation’s `/apps` (or `/custom_apps`) folder:
   ```
   git clone https://github.com/ernolf/raw
   ```
2) Enable the app:
   ```
   occ app:enable raw
   ```
   or log into Nextcloud as admin, find and enable it in the list of apps.

## Updating

1) Disable the app:
   ```
   occ app:disable raw
   ```
2) Update the code (e.g. git pull in the app directory, git clone again from scratch or unpack a new archive)
3) Enable the app again:
   ```
   occ app:enable raw
   ```

---

This app is currently not published in the Nextcloud app store.

---

[Content-Security-Policy]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy
[`child-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/child-src
[`connect-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/connect-src
[`default-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/default-src
[`font-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/font-src
[`frame-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/frame-src
[`img-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/img-src
[`manifest-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/manifest-src
[`media-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/media-src
[`object-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/object-src
[`script-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/script-src
[`style-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/style-src
[`worker-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/worker-src
[`base-uri`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/base-uri
[`sandbox`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/sandbox
[`form-action`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/form-action
[`frame-ancestors`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/frame-ancestors
[`upgrade-insecure-requests`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/upgrade-insecure-requests
