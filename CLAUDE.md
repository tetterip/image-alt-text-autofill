# Image Alt Text Autofill — Claude Code Context

## Idea

Our alternative to wordpress.org's "Auto Image Alt" (filename only, unmaintained since 2019). Fills missing image alt
text on **any** WordPress site (not WooCommerce-only): from a template (cleaned file name, titles) or with AI through the
**WordPress 7.0 AI Client** (`wp_ai_client_prompt()`), whose provider and key the site owner sets in Settings → Connectors.
**Distributed through WordPress.org only** (Michalis, 2026-11-27): not on plugins.ttrp.gr, not in the update server,
no ttrp-common, no `Update URI`. Keep it GPL and within the plugin guidelines: no keys stored, no upsells, no calls home,
no external assets.

## Architecture

```
image-alt-text-autofill.php            Bootstrap: header, constants, requires, init, WP-CLI
uninstall.php                     Deletes tet_alt_settings and our meta; alt texts stay (site content)
includes/
  class-tet-alt-logic.php         Pure (tested): clean_filename, apply_template, sanitize_alt, <img> helpers, may_write
  class-tet-alt-settings.php      Option tet_alt_settings (get()/on()), Settings API registration, Settings tab
  class-tet-alt-ai.php            status() (no API call: is_supported_for_text_generation with a 1px image), describe(), prompt
  class-tet-alt-generator.php     template_vars, from_template, generate (AI → template fallback), apply (scope), save
  class-tet-alt-media.php         Upload hook, front-end fill-in, Media Library column/bulk action/modal button, AJAX generate
  class-tet-alt-admin.php         Media → Alt Text (Bulk / Settings), counts, bulk AJAX, privacy text
  class-tet-alt-cli.php           wp alt-autofill generate|stats
assets/js/media.js, bulk.js       Plain JS, no build
tools/i18n/                       el.mjs + build.mjs (node tools/i18n/build.mjs); not shipped
readme.txt                        WordPress.org readme (Stable tag, External services section)
```

## Key decisions

- **AI only through the core AI Client.** No own provider code or key storage. Available = `wp_ai_client_prompt()` exists,
  `wp_supports_ai()`, and a configured model accepts text+image (checked without a request). Image sent inline as a data
  URI ("large" size, ≤ 4 MB, JPEG/PNG/GIF/WebP) so local/staging sites work. Prompt asks for the site language
  (`determine_locale()`), ≤ max_length chars, no "image of"; `sanitize_alt()` cleans what comes back anyway.
- **Who may spend AI requests:** setting `ai_roles` (default administrator + editor; administrators always). `Tet_Alt_AI::user_can()`
  (filter `tet_alt_ai_user_can`; WP-CLI and cron pass). Other users' uploads / Generate clicks get the template, regardless of
  "If AI fails". The cost notice text is `Tet_Alt_Settings::cost_text()` (Settings + Bulk, shown by bulk.js when AI is chosen).
- **Who wrote it:** meta `_tet_alt_source` = `template` | `ai`. Any other write of `_wp_attachment_image_alt` (a person,
  another plugin) deletes it (`updated_post_meta`), so it counts as hand-written. `save()` writes the alt first and the
  flag after, so its own write passes. Scopes: `missing`, `auto` (missing + template), `all`. A replaced alt goes to
  `_tet_alt_previous`.
- **Upload:** `wp_generate_attachment_metadata` (context `create`), inside the upload request, so the REST response the
  block editor inserts already has the alt. AI adds a few seconds per upload; failures fall back to the template.
- **Render:** `wp_get_attachment_image_attributes` + `wp_content_img_tag`, front end only, template only (never AI, never saved).
  Content images use the attachment's saved alt first (blocks inserted before the alt existed).
- **Bulk:** list IDs once (≤ 20000), then batches of 3 (AI) / 25 (template) via AJAX; the Media Library bulk action
  redirects to this page with `ids`.
- WooCommerce is optional: HPOS compatibility is declared only to avoid WC's warning; product titles come in through `{parent}`.

## Conventions

- PHP 7.4+, WordPress Coding Standards, escape/sanitise, nonce + capability on every AJAX action
  (generate: `edit_post`; bulk/settings: `manage_options`).
- Prefix `tet_alt_` / `Tet_Alt_` / `TET_ALT_`; text domain `image-alt-text-autofill`.
- `Tet_Alt_Admin::PLUGIN_TITLE` ("Image Alt Text Autofill") is never translated (a name).
- No `load_plugin_textdomain()` (Plugin Check flags it): WordPress loads translations itself, from WordPress.org language
  packs and, since 6.8, from the bundled `languages/` via the `Domain Path` header.
- After changing strings: add Greek to `tools/i18n/el.mjs`, run `node tools/i18n/build.mjs`.
- Bump `Version:` (header), `Stable tag` (readme.txt) and `CHANGELOG.md` together. GitHub release: Actions → Release → Run
  workflow (cloud sessions can't push tags). WordPress.org: SVN `trunk` + `tags/X.Y.Z` (after approval).
- Before each WordPress.org release run the Plugin Check plugin (PCP) on a test site.
- Keep the privacy texts (README, readme.txt "External services", `privacy_policy_content()`) true to what is sent.

## Testing

- `php tests/run.php` (pure logic).
- 2026-11-27: tested on WordPress 7.3-alpha + SQLite with a fake vision provider registered in the AI Client: upload
  (template, AI, AI failure → template), render fill-in (featured image, block image, external image, decorative alt=""),
  bulk page (AI, preview, scopes), Media Library column + bulk action, media modal button, settings save.
  **Not yet tested against a real provider** (Anthropic / Google / OpenAI provider plugins) or on WordPress < 7.0.

## Ideas (not scheduled)

- Image title / caption generation; per-post-type rules; WooCommerce product gallery context (product categories).
- Background queue (Action Scheduler) for very large libraries; undo button using `_tet_alt_previous`.
- Block editor sidebar button ("Generate alt" in the Image block inspector).
