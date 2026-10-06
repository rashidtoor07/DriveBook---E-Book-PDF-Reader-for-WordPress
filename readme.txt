=== VWL Flip Book ===
Contributors: visionweblabs, rashidtoor
Tags: pdf, ebook, flipbook, google drive, pdf viewer
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display public Google Drive PDFs as a responsive e-book with page turns, search, contents, zoom, dark mode and fullscreen.

== Description ==

Paste a Google Drive PDF link into a shortcode, a block, or an E-Books library entry and visitors get a book-style reader:
two-page spreads with a page-turn animation on wide screens, single pages with swipe and pinch-zoom on phones,
search with highlights, table of contents from PDF bookmarks, dark mode, fullscreen, reading-position memory,
an optional cover screen and an end-of-book message.

PDF.js 3.11.174 (Apache-2.0, Mozilla) is bundled. No Node.js, Composer or build step is required.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose vwl-flip-book.zip → Install Now → Activate.
2. In Google Drive set the PDF to General access: "Anyone with the link" (Viewer).
3. Add [vwl_ebook url="https://drive.google.com/file/d/FILE_ID/view"] to a page, or use the "VWL Flip Book" block.

See README.md for the full guide.

== External services ==

This plugin connects to Google Drive, a file hosting service provided by Google LLC. It is needed to display the PDF files you link to: the plugin does not host your books, it fetches them from Google Drive.

What is sent and when:

* When a page with a book is viewed and the PDF is not already cached on your server (the cache lasts 24 hours by default and can be changed or turned off in the settings), your WordPress server requests the file from drive.google.com / drive.usercontent.google.com. The request contains the Google Drive file ID taken from the link you entered, plus your server's IP address and a "VWL-Flip-Book" user-agent, as with any web request. No visitor data is sent; visitors download the PDF from your own site.
* When an administrator or editor uses "Test a Google Drive link" on the settings screen, your server makes the same kind of request for that file ID.
* If "Offer the Google Drive viewer if the reader cannot load a book" is enabled and a visitor clicks that button after an error, the Google Drive preview page for that file is loaded in an iframe in the visitor's browser. Google then receives the visitor's IP address, browser information and any Google cookies, as on any Google page. This option can be turned off under Settings → VWL Flip Book.

Google Terms of Service: https://policies.google.com/terms
Google Drive Additional Terms of Service: https://www.google.com/drive/terms-of-service/
Google Privacy Policy: https://policies.google.com/privacy

== Changelog ==

= 1.0.0 =
* Initial release.
