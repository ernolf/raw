# Raw — Nextcloud raw file server

Raw simply returns any requested file, so you can link directly to a file itself (i.e. without any
of NextCloud's interface around it). This enables you to host static web pages, images or other
files, for example to link/embed them elsewhere on the web.

For security and privacy, the content is served with a [Content-Security-Policy][] header. This
header instructs browsers to not load any remote content, nor execute any scripts that it may
contain (of course, the downside is that your web pages cannot use javascript for interactivity).

## Usage

The common usage is to first share a file and enable public access through a link. If the share link
is `https://my-nextcloud/s/aBc123DeF456xyZ`, then this app will provide access to the raw file at
`https://my-nextcloud/apps/raw/s/aBc123DeF456xyZ`.  If the share is a folder, the files within it
are accessible as e.g. `https://my-nextcloud/apps/raw/s/aBc123DeF456xyZ/path/to/file`.

A user can also access their own private files. For example, a file named `test.html` in anansi's
Documents folder would be available at `https://my-nextcloud/apps/raw/u/anansi/Documents/test.html`.

## Installation

Clone this repo into your Nextcloud installation's `/apps` (or `/custom_apps`) folder:

    git clone https://github.com/ernolf/raw

Then log into Nextcloud as admin, find and enable it in the list of apps.

This app is currently not published in the Nextcloud app store.


[Content-Security-Policy]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy
