=== KI-Kennzeichnung ===
Contributors: adilinu94
Tags: ai, label, images, elementor, compliance
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Labels AI-generated images reliably: badge burned into the file and/or overlay, Elementor containers/flexbox/image widgets, alt text, IPTC metadata, page audit.

== Description ==

* Mark images in the media library (checkbox, bulk actions) or detect them from metadata (IPTC/C2PA DigitalSourceType, AI software, Stable Diffusion/ComfyUI fields).
* Three modes: burned into the file, overlay, or automatic (file badge plus overlay only where the burned-in badge would be cropped or too small).
* Every image size is stamped individually and stays legible. Originals are kept in a protected folder and restored when you unmark.
* Elementor: containers (flexbox), sections, columns, image widgets and all background images (desktop/tablet/mobile, overlay, slideshow, dynamic tags) are detected by attachment ID.
* Alt-text suffix, IPTC DigitalSourceType in the file, site notice, CSV evidence export and a page audit.
* No third-party requests (no Google Fonts).

This plugin is a technical tool, not legal advice.

== Installation ==

1. Upload the plugin and activate it.
2. Open Settings → KI-Kennzeichnung.
3. Mark images in the media library.

== Frequently Asked Questions ==

= Does it work without GD? =
The overlay works without GD. Burning the badge into files needs the PHP GD extension.

= What happens when I uninstall? =
Settings and metadata are removed. Stamped images and the folder with the originals (uploads/ki-kennzeichnung-originals/) are kept on purpose.

== Changelog ==

= 2.0.0 =
Major rewrite, see CHANGELOG.md in the repository.
