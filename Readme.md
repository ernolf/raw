# raw — Nextcloud raw file server

**`raw`** simply returns any requested file, so you can link directly to the file itself (i.e. without any of Nextcloud's UI). This makes it easy to host static web pages, images or other files and link/embed them elsewhere on the web.

For security and privacy the content is served with a [Content-Security-Policy][] (CSP) header. You can configure CSP rules in detail via Nextclouds system `config/config.php` file (`raw_csp`). See the [CSP section](#content-security-policy-csp-configuration) below.

## Usage

The common usage is to first share a file and enable public access through a link. If the share link is

    `https://my-nextcloud/s/aBc123DeF456xyZ`

then this app will provide access to the raw file at

    `https://my-nextcloud/apps/raw/s/aBc123DeF456xyZ`

If the share is a folder, the files within it are accessible as e.g.

    `https://my-nextcloud/apps/raw/s/aBc123DeF456xyZ/path/to/file`

The /s/ can also be omitted, so

    `https://my-nextcloud/apps/raw/aBc123DeF456xyZ/path/to/file`

also works.


A user can also access their own private files. For example, a file named `test.html` in anansi's Documents folder would be available at

    `https://my-nextcloud/apps/raw/u/anansi/Documents/test.html`.

The /u/ can **not** be omitted, so

    `https://my-nextcloud/apps/raw/anansi/Documents/test.html`.

does **not** work.


## Token-Based Access Restrictions for Raw Content

The app makes use of a **whitelist mechanism** to control which tokens can be used to access raw content. This mechanism ensures that only explicitly defined tokens or tokens matching predefined patterns are allowed.

### Configuration Options:

One or both of the following arrays in the `config/config.php` of your Nextcloud instance must be defined, to configure token-based whitelist restrictions:

- **`allowed_raw_tokens`**  
  An array of explicitly allowed tokens. These tokens must exactly match those used in raw links.

- **`allowed_raw_token_wildcards`**  
  An array of wildcard patterns (`*`) that allow for flexible matching of multiple tokens. Wildcards are translated into regular expressions for dynamic validation.

#### Example Configuration:

```php
<?php
$CONFIG = array (
// -
  'allowed_raw_tokens' =>
  array (
    0 => 'scripts',
    1 => 'modules',
    2 => 'includes',
    3 => 'html',
  ),
  'allowed_raw_token_wildcards' =>
  array (
    0 => '*sufix',
    1 => 'prefix*',
    2 => 'prefix*sufix',
    3 => '*infix*',
  ),
// -
);
```

In this configuration:
- Tokens such as `scripts`, `modules`, and `html` are explicitly allowed.
- Wildcard patterns like `*_json` or `nc-*` enable flexible matching, e.g., `data_json` or `nc-example`.

### Usage with Human-Readable Tokens:

In the example above, the share links were created as `custom public links`. Generating human-readable tokens instead of randomly generated ones, makes links more meaningful and easier to manage. 

For example:
- Instead of a random token like `aBc123DeF456xyZ`, you can use a meaningful token such as `html`, `javascript` or `data_json` for shared directories or prepend prefixes, append sufixes or include infixes to enable them as wildcard.

This approach enhances both usability and security by allowing administrators to control access to raw links more effectively while keeping token names meaningful and consistent.


## Content Security Policy (CSP) configuration

`raw` supports configurable Content-Security-Policy (CSP) rules via the Nextcloud system config key `raw_csp`. The CSP config lets admins tune how `raw` serves files from different paths, file extensions or MIME types, and — optionally — per share token.

> [!NOTE]
> If `raw_csp` is not defined, `raw` falls back to this safe, very restrictive CSP:
>
> ```
> "sandbox; default-src 'none'; img-src data:; media-src data:; style-src data: 'unsafe-inline'; font-src data:; frame-src data:"
> ```
>
> This fallback is implemented hardcoded inside of the app (not in `config.php`)

### Matching priority (how `raw` picks a policy):

When deciding which CSP to send, `raw` evaluates selectors in this order:

- `token` (optional) — exact match for a public share token (the share id that appears in public URLs).
- `path_prefix` — longest matching prefix. Supports absolute prefixes (starting with /apps/raw) and relative prefixes (matched against the path after /apps/raw/...).
- `path_contains` — substring match. The manager checks both the full request path and the path after /apps/raw so public and private URLs are covered.
- `extension` — file extension based match (e.g. html, json).
- `mimetype` — mime-type matching (e.g. text/html, application/json).
- hard-coded fallback (if nothing matches).

> [!NOTE]
> `token` is the share token assigned by Nextcloud for public shares. Private user paths (`/apps/raw/u/...`) do not carry a share token, therefore token cannot match on private URLs.

### Policy formats accepted:

A policy value for a selector may be one of:

- *String* — a full, single-line CSP header value (passed through and sanitized).
- *Indexed array* — list of directive strings; entries are joined with `;` .
- *Associative array* (recommended) — `'directive' => sources`. `sources` may be a string (space separated) or an array of strings. The manager normalizes values, deduplicates and outputs a canonical single-line header.

Allowed directive names are deliberately limited (to keep policies sane and safe). Examples include:  
`default-src`, `script-src`, `style-src`, `img-src`, `media-src`, `font-src`, `connect-src`, `frame-src`, `frame-ancestors`, `base-uri`, `form-action`, `worker-src`, `manifest-src`, `sandbox`, `upgrade-insecure-requests`, `block-all-mixed-content`.

Unknown/unsupported directives are ignored by the manager.

#### Example PHP config/config.php snippets:

1) Extension rule — make all .html permissive
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

2) Path prefix — relative and absolute
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
        'defaukt-src' => ["'self'"],
        'script-src'  => ["'self'", "'unsafe-inline'"],
        'img-src'     => ["'self'", "data:"],
      ),
    ),
  ),
// -
);
```

3) Path contains — substring match (public + private)
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

4) Token — per share-token policy (optional)
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

5) Combined example
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


**Testing**

After you update `config/config.php` (or deploy changes), test with curl:

# public share (token) URL
```sh
curl -I 'https://your-instance.example/apps/raw/s/html/calc.html'
```

# private user URL
```sh
curl -I 'https://your-instance.example/apps/raw/u/alice/Documents/html/calc.html'
```

Inspect the Content-Security-Policy: response header. If you do not get the expected policy:

- make sure the selector matches your URL form (token vs path vs extension),
- check `nextcloud.log` for exceptions from `CspManager` or syntax errors in your config array,
- remember that `token` only matches explicit share tokens.

## Notes & best practices:

- Review and update `allowed_raw_tokens` and `allowed_raw_token_wildcards` periodically to align with your security requirements.
- Use meaningful share tokens wherever possible for improved manageability.
- Validate CSP rules and token configurations in a test environment before applying them in production.
- Prefer `extension` or `path-based` matching for predictable results. `path_contains` with `'/html/'` is usually the safest way to target a folder named html.
- Avoid `script-src 'unsafe-inline'` unless absolutely necessary (security risk). When you need inlined scripts, prefer script nonces or restrict carefully.
- Keep token selector only if you want per-share (per-token) policies. If you do not need that granularity, it is safe to remove `token` and rely on path/extension/mimetype rules.
- The manager normalizes directives and removes duplicates; unknown directives are ignored (no crash but check logs).

### Quick admin workflow

1. Edit config/config.php and add the raw_csp block (examples above).
2. Save and test URLs with curl -I.
3. Inspect Content-Security-Policy header.
4. If a policy is not applied, check nextcloud.log for manager warnings/exceptions.


## Conditional Requests / Cache validation with ETags and Last-Modified

`raw` supports conditional requests (cache validation) using ETags together with the `If-None-Match` header and also supports `Last-Modified` / `If-Modified-Since` semantics.

- **ETag / If-None-Match**: The server sends an `ETag` header identifying the current representation of the file. If the client sends `If-None-Match: "<ETag>"` and the value matches, the server responds with `304 Not Modified` and no response body. The wildcard `If-None-Match: *` is also supported.
- **Last-Modified / If-Modified-Since**: When the server can read file modification time (mtime) it sets a `Last-Modified` header. The server will honor `If-Modified-Since` when `If-None-Match` is not present. If the client date is equal to or newer than the file mtime, the server responds with `304 Not Modified`.
- **Unix timestamp convenience**: For convenience, `If-Modified-Since` accepts either a RFC-style HTTP-date (recommended) **or** a plain Unix timestamp (seconds). The server will trim optional quotes. Note that RFC-style dates are the standard and should be preferred for interoperability.
- **Cookie policy**: `raw` will attempt to avoid sending cookies in responses by removing any `Set-Cookie` headers that PHP/Nextcloud may have queued for the current request. This prevents PHP-emitted cookies from being delivered to clients.

### Examples

Get file and see headers + body (returns ETag and Last-Modified):
```bash
curl -i 'https://your.nextcloud/apps/raw/.../file.ext'
```

Conditional GET using ETag (should return 304 if it matches):
```bash
curl -i -H 'If-None-Match: "<ETag>"' 'https://your.nextcloud/apps/raw/.../file.ext'
```

Conditional GET using HTTP-date:
```bash
curl -i -H 'If-Modified-Since: "Sun, 25 May 2025 21:40:02 GMT"' 'https://your.nextcloud/apps/raw/.../file.ext'
```

Conditional GET using Unix timestamp (convenience):
```bash
curl -i -H 'If-Modified-Since: "1748209203"' 'https://your.nextcloud/apps/raw/.../file.ext'
```

Example request header (replace `<ETag>` with the ETag value returned by the server):
```bash
If-None-Match: "<ETag>"
```

The wildcard `If-None-Match: *` is also supported (it matches any existing representation) and will return a 304 if the resource exists.
```bash
curl -i -H 'If-None-Match: *' 'https://your.nextcloud/apps/raw/.../file.ext'
```

## Installation

Clone this repo into your Nextcloud installation's `/apps` (or `/custom_apps`) folder:

    git clone https://github.com/ernolf/raw

Then log into Nextcloud as admin, find and enable it in the list of apps.

This app is currently not published in the Nextcloud app store.


[Content-Security-Policy]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy
