# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Parich Long Slug** replaces the abandoned Long URL Maker 2.0.1: post and term slugs longer than WordPress's 200 characters, cut on Thai grapheme boundaries, that survive WordPress database updates — plus a repair tool for the damage Long URL Maker did (slugs cut, rewritten from titles, put back to old values) with 301 redirects and undo.

Pure PHP, no build step. Requires WordPress 6.2 (the `%i` identifier placeholder of `$wpdb->prepare()`) and PHP 7.4 — no PHP 8 syntax (`node tools/check-code.js` enforces it).

Phased plans (made with `/plan`) live in `docs/plans/`, which is gitignored: local working notes. When one exists, read it first and keep its checkboxes up to date.

## Conventions

- Slug / text domain `parich-long-slug`; PHP prefix `parich_slug_` (options, filters, tables, actions); classes `Parich_Slug_*` in `class-parich-slug-*.php`; constants `PARICH_SLUG_*`; WP-CLI `wp parich-slug`
- PHP style: tabs, braces on a new line for functions and classes, `array()`; every direct query has a `phpcs:ignore` with its reason
- SQL: values only through `$wpdb->prepare()`, table and column names only through `%i` (`tools/check-code.php` checks this — L7). Plugin Check (0 warnings) also needs every format written in place: a literal, plus `' . implode(',', array_fill(0, count($ids), '%d')) . '` for IN lists. A format put together in a variable (`.=`, a ternary, reassigned, a helper's return value) is reported even when it holds only literals — use one fixed query per case, or `(%s = '' OR …)` for an optional filter (see `Parich_Slug_Repair_Scanner::load_items()`)
- Never `GROUP BY`/`DISTINCT` a long slug column directly: MySQL 8.4 failed to group equal long values of a prefix-indexed varchar — group by `MD5(col)` or a stored hash
- Never `load_plugin_textdomain()`, no `Domain Path`, no translation files in the zip (WordPress.org language packs)
- Admin notices (guideline 11, raised by the first WordPress.org pre-review): administrators only, only on the Dashboard, the Plugins screen and the plugin's page (`Parich_Slug_Admin::is_notice_screen()`), only while the situation lasts, no promotion; information about this plugin on the Plugins screen goes under its own row (`after_plugin_row_*`), not into a notice. Tests set the screen with `pls_test_screen()` / `pls_test_notices()`
- Strings are English; Thai lives in `languages/parich-long-slug-th.po`, built from `tools/th-strings.js`

## Commands

```bash
npm test                 # tests/php/*.php against the local WordPress (node tests/run-php-tests.js [filter])
npm run test:selftest    # the runner must fail on planted mistakes
npm run lab:scan         # repair scan on every damage lab snapshot: auto precision, recall, read-only
npm run lab:apply        # repairs, redirects and undo on the snapshots (rolled back)
npm run check            # L7 SQL and PHP 7.4 syntax (self-test first)
npm run po               # languages/parich-long-slug-th.po from the .pot and tools/th-strings.js
npm run plugin-zip       # parich-long-slug.zip from "files" in package.json; prints SHA-256
npm run plugin-check     # Plugin Check on the unpacked zip (0 errors and 0 warnings expected; --warnings lists them)
node tools/wporg-assets.js art           # .wordpress-org/ icon and banners from tools/art/ (Edge or Chrome headless)
node tools/wporg-assets.js screenshots   # .wordpress-org/screenshot-1…3.png: admin pages, repair tab from lab snapshot B
node tests/damage-lab/run-lab.js [--no-d3]   # makes the snapshots with the REAL Long URL Maker (backs up and restores the whole database)
node tests/damage-lab/http-apply.js          # snapshot B in the real tables, repair, HTTP checks, restore
node tests/e2e/run-e2e.js                    # real database upgrade with and without the guard, deactivate, uninstall, restore
```

`run-lab.js`, `http-apply.js` and `run-e2e.js` restore the whole database at the end: anything edited on the site while they run is lost. `wporg-assets.js screenshots` does not: it loads snapshot B under `plslab_s_` (scan, render and repair in `tests/damage-lab/render-repair.php`, tables dropped after), shows that content inside the site's real repair page (a temporary login session, destroyed after), hides the toolbar, menu and footer, and keeps only table rows whose titles name no real organisation.

Regenerate the template after changing strings: `wp i18n make-pot . languages/parich-long-slug.pot --slug=parich-long-slug --domain=parich-long-slug --exclude=tests,docs,tools,languages,node_modules`, then `npm run po`.

## How long slugs stay safe

- `Parich_Slug_Schema` widens `post_name` / `slug` to varchar(2000) on activation (never shrinks); `maybe_check_db_version()` (plugins_loaded 5) heals after a database update the guard missed: widen again, then put back slugs cut to exactly 200 characters from the shadow copy (lock: `INSERT IGNORE` option row)
- `Parich_Slug_Guard` copies `includes/guard-template.php` to `wp-content/mu-plugins/parich-long-slug-guard.php` (bump `Guard Version` when the template changes; `admin_init` reinstalls outdated copies). It filters `dbdelta_create_queries` (must-use plugins load during `upgrade.php`, normal plugins do not) and answers Long URL Maker's version options so its file-level restore never runs
- `Parich_Slug_Shadow`: one row per slug over 200 characters, synced on `wp_insert_post`, `add_attachment`/`attachment_updated` and term hooks
- `Parich_Slug_Sanitize`: a copy of core's `sanitize_title_with_dashes()` that encodes up to the setting (200–1900 bytes) on grapheme boundaries; `restore_thai_marks()` puts back the Thai marks the block editor's slug field strips
- `Parich_Slug_Legacy`: removes Long URL Maker's hooks, switches it off if activated again, safe-deactivate button

## Repair tool (`includes/repair/`)

- Damage (detectors): D1 cut at 200, D2 rewritten from the title (its deactivation routine; `legacy_old()`), D3 put back to an old slug, D4 shared slug, D5 cut to fit "-2" at creation, D6 keys of system data (`class-parich-slug-repair-system-keys.php`: ACF, templates, global styles, custom CSS), D7 terms
- Evidence: S1 Long URL Maker's backup table, S2 `_wp_old_slug` (staleness only), S3 `_wp_desired_post_slug`, S4 Yoast/Rank Math, S5 404 logs, S6 redirects, S7 links, S8 titles and revisions, S9 imported URLs/CSV, S10 guid. Columns shorter than the URLs they hold give prefixes only
- Scorer: `auto` needs one strong source or two independent medium ones and nothing strong against it; D3, D5, conflicts, stale backups, contested or taken values go to `review`. `auto` must stay 100% right on the lab (`npm run lab:scan`)
- Scanner: steps urls → posts → terms → system → d4 → redecide → contest → collisions → structure; results in `{prefix}parich_slug_scan_items` / `_scan_urls` (also imports, scan 0); reads any table prefix (`Parich_Slug_Repair_Source`) — the lab uses `plslab_*_`
- Applier: terms before posts, parents before children; plain `UPDATE … WHERE slug = <as scanned>` (no `save_post`, `post_modified` untouched); log, shadow, a row in Long URL Maker's table, 301s from every old address the object owned (also children and posts under a category); undo per batch
- `Parich_Slug_Redirects`: `template_redirect` priority 1, only on 404 and only while `parich_slug_redirect_count` (autoloaded) is non-zero; targets resolved from the object at request time

## Tests

- `tests/bootstrap.php`: loads WordPress and the plugin; each file runs in a transaction that is rolled back (sentinel row; DDL inside a transaction is blocked); `PLS_TEST_MANUAL_TX` for files that copy tables first; plugin notices and deprecations count as failures; a file with no assertion fails
- Never name top-level test variables `$post`, `$page`, `$id`: `WP::main()` and REST overwrite those globals
- `wp eval-file` runs a file inside a function: top-level variables are not globals (use `$GLOBALS` for counters)
- `$wpdb->get_var()` returns null for an empty string; `new WP()` lacks the query vars of registered post types
- Damage lab: `run-lab.js` lets the real Long URL Maker damage the local site, saves `damaged-active.sql` (A), `damaged-deactivated.sql` (B), `damaged-active-no-d3.sql` with `.truth.json` (gitignored), then restores the database. `load.js <sql> plslab_x_` loads a snapshot under another prefix; `eval-apply.php` points WordPress at it with `$wpdb->set_prefix()`
- Every check has a negative control: `PLS_NEGATIVE=write` (test-14), `PLS_NEGATIVE=no-redirects` / `no-acf` (`apply-lab.js`), `--selftest` (runner, check-code)
- `tests/local-config.json` (gitignored; copy `local-config.example.json`): PHP binary and arguments, WP-CLI, mysql client, backup folder, host

## Release

1. `npm test`, `npm run lab:scan`, `npm run lab:apply`, `npm run check`
2. Bump `Version` (main file and `PARICH_SLUG_VERSION`), `Stable tag`, `package.json`; changelog in `readme.txt`; `Tested up to` only after testing that WordPress version
3. `npm run plugin-zip`, `npm run plugin-check`, `node ~/.claude/skills/wporg-plugin-submission/scripts/precheck.mjs . --zip parich-long-slug.zip`
4. Directory images: `.wordpress-org/` (icon, banners, `screenshot-N.png` matching `== Screenshots ==` in readme.txt) goes to SVN `/assets`, not into the zip; remake them with `node tools/wporg-assets.js art|screenshots` when the pages change
5. No GitHub updater: updates come from WordPress.org (guideline 8)
