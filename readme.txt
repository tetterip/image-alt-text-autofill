=== Image Alt Text Autofill ===
Contributors: tetterip
Tags: alt text, accessibility, images, seo, ai
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Writes missing image alt text: from file names and titles, or with AI through the provider set up in WordPress.

== Description ==

Alt text describes an image for people who use screen readers and helps search engines understand it. Image Alt Text Autofill fills in the alt text your images are missing.

* **AI descriptions (WordPress 7.0+)** through the AI Client built into WordPress. Choose a provider and enter its key in Settings → Connectors; this plugin never handles keys. Alt texts are written in the site language, short, with the post or product title as optional context.
* **Template, no external service:** `{filename}` turns `red-leather_sofa-2-1024x768.jpg` into "Red leather sofa" and ignores camera names such as `IMG_1234`. Also `{title}`, `{caption}`, `{parent}` and `{site}`.
* **On upload:** new images get their alt text immediately.
* **On the site:** images shown without alt text get a template one in the output (nothing saved).
* **Bulk tool** with preview for existing images; alt texts written by people are only replaced if you ask, and the previous text is kept.
* **Media Library** column, Generate buttons, bulk action and a button in the media modal.
* **WP-CLI:** `wp alt-autofill generate`, `wp alt-autofill stats`.

= External services =

Only when you choose the AI option: each image being described (JPEG, PNG, GIF or WebP, normally the "large" size) is sent with the site name and, if enabled, the title of its post or product, to the AI provider you configured in WordPress under Settings → Connectors (for example Anthropic, Google or OpenAI). That provider's terms and privacy policy apply. Without the AI option, no data leaves your site.

== Frequently Asked Questions ==

= Do I need an API key? =

Only for AI descriptions. Install a provider plugin (for example "AI Provider for OpenAI") and enter the key in Settings → Connectors. The template works without anything.

= Will it overwrite alt texts I wrote? =

No, unless you pick "All images" in the bulk tool. Editing an alt text by hand marks it as yours.

= What about decorative images with alt=""? =

By default an empty alt is treated as missing. Untick "Treat an empty alt as missing" to keep them.

== Changelog ==

= 0.1.0 =
* First release.
