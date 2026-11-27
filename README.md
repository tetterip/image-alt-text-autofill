<p align="center">
  <img src=".github/icon.svg" width="112" height="112" alt="">
</p>

<h1 align="center">Image Alt Text Autofill</h1>

<p align="center"><strong>Writes missing image alt text for you: from file names and titles, or with AI through the provider you set up in WordPress.</strong></p>

<p align="center">
  <a href="https://github.com/tetterip/image-alt-text-autofill/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/tetterip/image-alt-text-autofill?label=release&color=7c3aed"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/github/license/tetterip/image-alt-text-autofill?color=2563eb"></a>
  <img alt="WordPress 6.2+ (AI: 7.0+)" src="https://img.shields.io/badge/WordPress-6.2%2B%20%C2%B7%20AI%207.0%2B-21759B?logo=wordpress&logoColor=white">
  <img alt="PHP 7.4+" src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white">
  <a href="https://github.com/tetterip/image-alt-text-autofill/actions/workflows/tests.yml"><img alt="Tests" src="https://github.com/tetterip/image-alt-text-autofill/actions/workflows/tests.yml/badge.svg"></a>
</p>

<p align="center">
  <a href="https://github.com/tetterip/image-alt-text-autofill/releases/latest"><strong>Download</strong></a> ·
  <a href="CHANGELOG.md">Changelog</a> ·
  <a href="https://github.com/tetterip/image-alt-text-autofill/issues/new/choose">Report a bug</a> ·
  <a href=".github/CONTRIBUTING.md">Contributing</a>
</p>

<!--
  Screenshots: save PNGs (about 1600 px wide) in .github/screenshots/ and list them here.
-->

## What it does

Alt text describes an image for people who use screen readers, and helps search engines understand it. Most media
libraries are full of images without it. Image Alt Text Autofill fills the gaps, on any WordPress site (WooCommerce optional).

- **AI descriptions (WordPress 7.0+).** Uses the AI Client built into WordPress: you pick a provider (Anthropic,
  Google, OpenAI…) and enter its key once in **Settings → Connectors**. The plugin never sees or stores a key. Alt texts
  are written in the language of the site, kept short (125 characters by default), and can use the title of the post or
  product the image belongs to as context.
- **Template, no external service.** `{filename}` turns `red-leather_sofa-2-1024x768.jpg` into “Red leather sofa” and
  ignores camera names such as `IMG_1234`. Also `{title}`, `{caption}`, `{parent}` (post / page / product title) and
  `{site}`, e.g. `{filename} - {site}`. Also the fallback when AI is off or fails.
- **On upload.** New images get their alt text as they are uploaded, so it is already there when the editor inserts them.
- **On the site.** Images shown without alt text get a template one in the page output (nothing is saved, no AI).
- **Bulk.** **Media → Alt Text** fixes existing images in batches, with a preview, and can upgrade
  automatic (template) alt texts to AI ones. Alt texts written by people are only replaced if you ask, and the old text is kept.
- **Media Library.** An “Alt text” column with a Generate button, a bulk action, and a “Generate alt text” button in the
  media modal.
- **WP-CLI.** `wp alt-autofill generate [--source=ai] [--scope=missing|auto|all] [--dry-run] [--limit=N]`, `wp alt-autofill stats`.

## Privacy

Without AI, nothing leaves the site. With AI, each image that gets described is sent, with the site name and the title of
its post or product, to the AI provider the site owner set up in WordPress. Visitors' data is never sent. A suggested
paragraph is added to **Settings → Privacy → Policy Guide**.

The plugin itself contacts no other server: updates come from WordPress.org like any other plugin.

## Developers

| Hook | Type | Use |
|---|---|---|
| `tet_alt_template_vars` | filter | Add or change `{tags}` (`$vars`, `$attachment_id`) |
| `tet_alt_ai_prompt` | filter | Adjust the AI Client builder, e.g. `->using_model_preference( … )` |
| `tet_alt_ai_instruction` | filter | The system instruction sent with every image |
| `tet_alt_ai_context` | filter | Context lines sent with the image |
| `tet_alt_ai_user_can` | filter | Whether the current user may use AI (default: the roles under "Who can use AI") |
| `tet_alt_saved` | action | After an alt text was generated and saved (`$id`, `$alt`, `$source`) |

Meta: `_tet_alt_source` (`template` / `ai`, removed when someone edits the alt by hand) and `_tet_alt_previous`.

## Requirements

WordPress 6.2+ (AI features: 7.0+ with an AI provider plugin and key), PHP 7.4+.

## Security

Please report vulnerabilities privately to **info@ttrp.gr**, see [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. © Michalis Tetteris, [ttrp.gr](https://ttrp.gr).
