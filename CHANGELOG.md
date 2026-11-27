# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2026-11-27

### Added
- Alt text from a template (`{filename}`, `{title}`, `{caption}`, `{parent}`, `{site}`); file names are cleaned of sizes, WordPress suffixes and camera names such as `IMG_1234`.
- AI descriptions through the WordPress 7.0 AI Client (provider and key in Settings → Connectors), in the site language, with the post / product title as context; falls back to the template.
- Alt text written on upload, and added to images shown without one on the site.
- Bulk page with preview, scopes (missing / automatic / all) and progress; replaced alt texts are kept.
- Media Library column, Generate buttons, bulk action and a button in the media modal.
- WP-CLI commands `wp alt-autofill generate` and `wp alt-autofill stats`.
- Suggested privacy policy text; Greek translation.
