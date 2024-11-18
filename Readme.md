# Raw — Nextcloud raw file server

Raw simply returns any requested file, so you can link directly to a file itself (i.e. without any of NextCloud's interface around it). This enables you to host static web pages, images or other files, for example to link/embed them elsewhere on the web.

For security and privacy, the content is served with a [Content-Security-Policy][] header. This header instructs browsers to not load any remote content, nor execute any scripts that it may contain (of course, the downside is that your web pages cannot use javascript for interactivity).

## Usage

The common usage is to first share a file and enable public access through a link. If the share link is

    `https://my-nextcloud/s/aBc123DeF456xyZ`

then this app will provide access to the raw file at

    `https://my-nextcloud/apps/raw/s/aBc123DeF456xyZ`

If the share is a folder, the files within it are accessible as e.g.

    `https://my-nextcloud/apps/raw/s/aBc123DeF456xyZ/path/to/file`

The /s/ can also be omitted, so

    `https://my-nextcloud/aBc123DeF456xyZ`

or 

    `https://my-nextcloud/apps/raw/aBc123DeF456xyZ/path/to/file`

also works.


A user can also access their own private files. For example, a file named `test.html` in anansi's Documents folder would be available at

    `https://my-nextcloud/apps/raw/u/anansi/Documents/test.html`.

The /u/ can **not** be omitted, so

    `https://my-nextcloud/apps/raw/anansi/Documents/test.html`.

does **not** work.


## Token-Based Access Restrictions for Raw Content

The app makes use of a **whitelist mechanism** to control which tokens can be used to access raw content. This mechanism ensures that only explicitly defined tokens or tokens matching predefined patterns are allowed.

### Configuration Options

One or both of the following arrays in the `config/config.php` of your Nextcloud instance must be defined, to configure token-based whitelist restrictions:

- **`allowed_raw_tokens`**  
  An array of explicitly allowed tokens. These tokens must exactly match those used in raw links.

- **`allowed_raw_token_wildcards`**  
  An array of wildcard patterns (`*`) that allow for flexible matching of multiple tokens. Wildcards are translated into regular expressions for dynamic validation.

#### Example Configuration

```php
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
```

In this configuration:
- Tokens such as `scripts`, `modules`, and `html` are explicitly allowed.
- Wildcard patterns like `*_json` or `nc-*` enable flexible matching, e.g., `data_json` or `nc-example`.

### Usage with Human-Readable Tokens

In the example above, the share links were created using the [cfg_share_links][] app. This app allows generating human-readable tokens instead of randomly generated ones, making links more meaningful and easier to manage. 

While the use of **cfg_share_links** is not mandatory, its installation is recommended for better control and usability when creating share links for raw access eg for html pages.

For example:
- Instead of a random token like `aBc123DeF456xyZ`, you can use a meaningful token such as `html`, `javascript` or `data_json` for shared directories or prepend prefixes, append sufixes or include infixes to enable them as wildcard.

This approach enhances both usability and security by allowing administrators to control access to raw links more effectively while keeping token names meaningful and consistent.


### Best Practices

Review and update allowed_raw_tokens and allowed_raw_token_wildcards periodically to align with your security requirements.
Use meaningful share tokens wherever possible for improved manageability.
Validate CSP rules and token configurations in a test environment before applying them in production.

## Installation

Clone this repo into your Nextcloud installation's `/apps` (or `/custom_apps`) folder:

    git clone https://github.com/ernolf/raw

Then log into Nextcloud as admin, find and enable it in the list of apps.

This app is currently not published in the Nextcloud app store.


[Content-Security-Policy]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy
[cfg_share_links]: https://github.com/jimmyl0l3c/cfg_share_links
