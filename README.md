# WP Flip Book

A WordPress plugin that turns a **public Google Drive PDF** into a responsive, interactive e-book reader.
Works on standard shared/cPanel hosting. No Node.js, npm, Composer or build step.

- Requires WordPress 5.8+ and PHP 7.4+ (tested on WordPress 6.8.3 / PHP 8.3)
- Bundles PDF.js 3.11.174 (Apache-2.0)

---

## 1. Installation

1. In WordPress go to **Plugins → Add New → Upload Plugin**.
2. Choose `wp-flip-book.zip`, click **Install Now**, then **Activate**.
3. Open **Settings → WP Flip Book** to review defaults (optional).

## 2. Google Drive setup (required)

The reader can only open PDFs that anyone can view.

1. Open [drive.google.com](https://drive.google.com) and right-click the PDF → **Share → Share**.
2. Under **General access**, change **Restricted** to **Anyone with the link**. Keep the role **Viewer**.
3. Click **Copy link**, then **Done**.
4. Keep **"Viewers can download, print and copy"** turned on (Share → ⚙ settings). If the owner turns it off,
   Google also blocks the download the reader needs and the book will not open.
5. Google Workspace (company) accounts may forbid sharing outside the organization. Ask your Workspace admin or
   use a personal Drive.

Test the link before publishing: **Settings → WP Flip Book → Tools → Test a Google Drive link**.

### Accepted link formats

```
https://drive.google.com/file/d/FILE_ID/view?usp=sharing
https://drive.google.com/file/d/FILE_ID/preview
https://drive.google.com/open?id=FILE_ID
https://drive.google.com/uc?id=FILE_ID&export=download
https://docs.google.com/file/d/FILE_ID/edit
FILE_ID
```

## 3. Usage

### Shortcode

```
[pdf_ebook url="https://drive.google.com/file/d/FILE_ID/view"]
```

All attributes are optional except `url`. Anything left out uses the site default from the settings page.

| Attribute | Values | Purpose |
|---|---|---|
| `url` | Drive link or file ID | The PDF |
| `title` | text | Shown in the toolbar and cover |
| `height` | `800`, `800px`, `85vh` | Reader height (phones cap it to the screen) |
| `theme` | `light` / `dark` / `auto` | Starting theme |
| `toolbar` | `true` / `false` | Show the top toolbar |
| `nav` | `true` / `false` | Previous / next buttons |
| `zoom` | `true` / `false` | Zoom buttons |
| `search` | `true` / `false` | Search |
| `toc` | `true` / `false` | Table of contents (only appears if the PDF has bookmarks) |
| `fullscreen` | `true` / `false` | Fullscreen button |
| `darkmode` | `true` / `false` | Dark mode toggle |
| `download` | `true` / `false` | Download button |
| `print` | `true` / `false` | Print button |
| `remember` | `true` / `false` | Offer "Continue reading from page N?" |
| `cover` | `true` / `false` | Cover screen with "Start reading" |
| `cover_image` | image URL | Cover image (otherwise page 1 is used) |
| `two_page` | `true` / `false` | Two-page spreads on wide screens |
| `mobile_single` | `true` / `false` | Force single page below 768 px |
| `animations` | `true` / `false` | Page-turn animation |
| `completion` | `true` / `false` | End-of-book message |
| `class` | CSS class | Extra class on the reader |

Example:

```
[pdf_ebook url="https://drive.google.com/file/d/FILE_ID/view" title="Digital Marketing Guide" height="800"
 theme="light" toolbar="true" download="false" print="false" search="true" fullscreen="true" toc="true"]
```

### Gutenberg block

Add the **WP Flip Book** block (Media category). Paste the link or choose a library book, then
set title, height, theme and features in the sidebar. The editor shows a preview card; the live reader runs on the
published page and in Preview. Supports wide and full alignment.

### E-Books library

**E-Books → Add New** lets you build a library. Each book has a title, Google Drive link, description (main
editor), featured image (used as the cover), author, categories, and per-book cover/download/print overrides.
Use `[e_book id="123"]` anywhere (the shortcode is shown on the edit screen and in the list). Each book also
has its own page at `/ebooks/book-slug/`. Attributes from the table above can be added to `[e_book]` to override.

### Page builders

Use a Shortcode widget/module (Elementor, Divi, Bricks, Kadence, Astra, GeneratePress…). Assets load only on
pages that contain a reader; on builder pages they are added when the shortcode renders.

## 4. Reader controls

| Action | Desktop | Phone / tablet |
|---|---|---|
| Turn page | ← → , PageUp/PageDown, edge arrows, footer arrows | Swipe, footer arrows |
| First / last page | Home / End | Page slider (wide screens) |
| Zoom | + / −, Ctrl/⌘ + wheel, toolbar | Pinch, double-tap, ⋮ menu |
| Search | Ctrl/⌘ + F (when the reader has focus), Enter / Shift+Enter for next/previous | Search button |
| Fullscreen | F, Esc to exit | Fullscreen button (in-page fullscreen on iPhone) |

Keyboard shortcuts only work while focus is inside the reader, so they never interfere with the rest of the site.

## 5. How it works (and why)

Google Drive does not send CORS headers, so browser-side PDF.js cannot read a Drive PDF directly. The plugin
includes a small same-origin relay (`admin-ajax.php?action=vwl_ebook_file`) that:

- downloads the file **anonymously**, so it only works for files that are already public; private files fail
  exactly as they would for any visitor. No Google credentials, cookies or API keys are used.
- only serves file IDs your own pages embed (each link carries an HMAC token; this is cache-safe, unlike nonces).
- caches the PDF in `wp-content/uploads/vwl-ebook-cache/` (unguessable file names plus `.htaccess` deny) for the
  configured hours; a daily cron removes expired copies.
- streams with HTTP Range support, so large PDFs (over 8 MB) load page by page instead of all at once.
- follows Google's "can't scan for viruses" confirmation page for large public files, like a browser would.

If your server can't reach Google, the reader can offer Google's own viewer as a fallback (setting on by default).

**Security:** PDF.js 3.11.174 is affected by CVE-2024-4367; the reader disables `isEvalSupported`, which is the
documented mitigation. Version 3.x is used instead of 4.x because 4.x ships only `.mjs` modules, which many
shared hosts serve with the wrong MIME type.

## 6. Download and print options

Turning off Download or Print hides those buttons and stops issuing download links. It is **not** DRM:
any PDF displayed in a browser can be captured by a determined visitor.

## 7. Analytics hooks

Enable **Settings → WP Flip Book → Completion → Send reader events to WordPress hooks**, then:

```php
add_action( 'vwl_ebook_opened',      function ( $data ) { /* ... */ } );
add_action( 'vwl_ebook_page_viewed', function ( $data ) { /* ... */ } );
add_action( 'vwl_ebook_completed',   function ( $data ) { /* ... */ } );
add_action( 'vwl_ebook_search',      function ( $data ) { /* $data['query'] */ } );
// $data: ebook_id, file_id, pdf_url, page, total_pages, query, source_url
```

No personal data is collected. The browser also fires DOM events on the reader element regardless of this
setting: `vwl-ebook:opened`, `vwl-ebook:page`, `vwl-ebook:completed`, `vwl-ebook:search` (use `event.detail`).

Developers can filter the JS config with `vwl_ebook_reader_config`, and override the markup by copying
`templates/reader.php` to `your-theme/wp-flip-book/reader.php`.

## 8. Troubleshooting

| Message / symptom | Cause | Fix |
|---|---|---|
| "…not shared publicly…" | Sharing is Restricted | Set "Anyone with the link", then click **Try again** (errors are remembered for 2 minutes; the Tools tab tester re-checks immediately) |
| "…could not find this file…" | Wrong link, deleted file, or private | Check the link and sharing |
| "…not a PDF…" | The link is a Google Doc/Slide or another file type | Export to PDF, upload the PDF, share that link |
| "…could not connect to Google Drive" | Host blocks outgoing HTTPS | Ask the host to allow drive.google.com and drive.usercontent.google.com, or use the Google viewer fallback |
| "…temporarily limited access…" | Google download quota on popular files | Keep caching on; try later |
| "…larger than the maximum size…" | Over the size limit | Raise **Performance → Maximum PDF size** |
| Old version after replacing the PDF | Cache | **Tools → Clear cached books** |
| Search: "does not contain searchable text" | Scanned/image-only PDF | Run OCR on the PDF before uploading (OCR isn't built in) |
| No Contents button | PDF has no bookmarks | Add bookmarks when exporting the PDF |
| Reader looks squeezed into one page on desktop | The content column is narrower than 768 px | Use the block with Wide/Full alignment, or a wider page template |
| Ajax blocked by a security plugin | Plugin blocks admin-ajax for visitors | Allow the actions `vwl_ebook_prepare`, `vwl_ebook_file` (and `vwl_ebook_event` if analytics is on) |

Uninstalling always removes cached files. Settings and library books are deleted only if
**Delete all plugin data on uninstall** is enabled.

## 9. File structure

```
wp-flip-book/
├── wp-flip-book.php              Bootstrap
├── uninstall.php
├── readme.txt / README.md
├── includes/
│   ├── class-vwl-ebook-plugin.php     Wiring, conditional assets, activation
│   ├── class-vwl-ebook-settings.php   Defaults + sanitization
│   ├── class-vwl-ebook-admin.php      Settings page, link tester, cache tools
│   ├── class-vwl-ebook-drive.php      URL parsing, HMAC tokens
│   ├── class-vwl-ebook-proxy.php      Public-file relay, cache, Range streaming
│   ├── class-vwl-ebook-renderer.php   Shared rendering + JS config
│   ├── class-vwl-ebook-shortcode.php  [pdf_ebook], [e_book]
│   ├── class-vwl-ebook-post-type.php  E-Books library
│   ├── class-vwl-ebook-block.php      Gutenberg block (server-rendered)
│   ├── class-vwl-ebook-analytics.php  Analytics hooks
│   └── class-vwl-ebook-icons.php      Inline SVG icons
├── templates/reader.php
├── assets/css/  reader.css, admin.css, block-editor.css
├── assets/js/   reader.js, admin.js, block.js
├── assets/vendor/pdfjs/  pdf.min.js, pdf.worker.min.js, cmaps/, standard_fonts/, LICENSE
└── languages/wp-flip-book.pot
```
