# WP Auto Updates

WordPress auto-updates are all-or-nothing by default. This plugin gives you granular control: major core updates, per-plugin and per-theme toggles, translations — plus a concise email report after updates run and the option to silence WP's own update emails.

## Features

- **Core major versions** — Inherit / Enable all / Disable all (minor/security updates always follow core behaviour)
- **Plugins & Themes** — Inherit, all-on, all-off, or *Custom*: tick exactly which plugins/themes may auto-update from a checkbox list of what's installed
- **Translation updates** — separate toggle
- **Email update report** — one digest after WP-Cron runs updates: what succeeded, what failed, with a link to Updates screen; sent only when updates actually ran
- **Suppress WP default emails** — optional; stops the per-update core/plugin/theme notification emails so your single report is the only mail

## Install

1. Copy `wp-auto-updates.php` and `uninstall.php` into `wp-content/plugins/wp-auto-updates/`
2. Activate the plugin
3. Go to **Settings → Auto Updates** and set your policy

## Files

- `wp-auto-updates.php` — the plugin
- `uninstall.php` — removes the `wau_settings` option

MIT licensed.
