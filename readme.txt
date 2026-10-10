=== Parich Long Slug ===
Contributors: parich
Donate link: https://buy.stripe.com/3cIaEW9699pf3dyeAfaMU00
Tags: slug, permalink, thai, long url, redirect
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Long, Thai-friendly slugs that survive WordPress updates, and a repair tool for slugs that Long URL Maker cut or rewrote.

== Description ==

WordPress stores slugs in a column 200 characters long. A Thai letter takes 9 characters once encoded for a URL, so a Thai title is cut after about 22 letters, often in the middle of a syllable. Parich Long Slug makes room for long slugs, keeps them safe through WordPress updates, and repairs the damage another plugin, Long URL Maker, has done on many sites.

= Long slugs =

* Slugs up to 1,000 bytes by default (about 110 Thai letters), adjustable from 200 to 1,900 under **Settings → Long Slug**, with a live preview.
* Cut on a letter boundary: vowels and tone marks stay with their consonant, and a slug never ends with a leading vowel (เ แ โ ใ ไ).
* Everything else works as in WordPress: the same characters are removed, and a slug that fits in 200 bytes comes out exactly as WordPress makes it.
* Works from every editor: the block editor, Quick Edit, the classic editor, REST and WP-CLI. When the block editor's slug field drops Thai vowels and tone marks, they are put back on save.

= Safe through WordPress updates =

When WordPress updates its database, it changes the slug columns back to 200 characters and cuts every longer slug. That is what breaks sites using Long URL Maker. This plugin:

* installs a small file in `wp-content/mu-plugins` (the "guard") that stops WordPress from shortening the columns, also during the update itself, when other plugins are not loaded;
* keeps a copy of every slug longer than 200 characters, and if the columns were shortened anyway (the guard was removed), widens them again and puts the cut slugs back on the next page load.

= Repair what Long URL Maker damaged =

Long URL Maker cuts slugs at 200 characters when WordPress updates, rewrites every slug from its title when it is deactivated, and puts old slugs back whenever the WordPress version changes. **Settings → Long Slug → Repair slugs** finds that damage and proposes the original slugs:

* It looks for evidence of what each slug was: Long URL Maker's own backup table, the slug a trashed post had, SEO plugin data (Yoast SEO, Rank Math), 404 logs (Redirection, Rank Math, 301 Redirects), existing redirects, links on your site, titles and their revisions, and URL lists or CSV files you import.
* Every proposal shows its evidence. Items with strong evidence are pre-selected as "Ready"; doubtful ones are left for you to check; the scan never changes anything by itself.
* Repairs change only the slug (not the modified date), in batches, and can be undone batch by batch.
* Every old address is redirected (301) to the repaired post or term, including the addresses of child pages and of posts whose links contain a repaired category.
* Keys used by WordPress and plugins are repaired too: ACF field and field group keys, templates and template parts, global styles, custom CSS.
* Addresses from 404 logs that match nothing can be pointed at a post by hand.

= For developers =

* WP-CLI: `wp parich-slug scan`, `repair`, `undo`, `redirects`, `import-urls`, `import-csv`, `clear-imports`.
* Filters: `parich_slug_max_length`, `parich_slug_capability`, `parich_slug_repair_post_types`, `parich_slug_repair_taxonomies`, `parich_slug_repair_system_post_types`, `parich_slug_guard_file`.

= Source code =

The complete source and its tests are on GitHub: [github.com/parich/parich-long-slug](https://github.com/parich/parich-long-slug).

== Installation ==

1. In your dashboard go to **Plugins → Add New Plugin**, search for "Parich Long Slug", then install and activate it. You can also upload the ZIP file under **Plugins → Add New Plugin → Upload Plugin**.
2. Activation widens the slug columns and installs the guard. Check **Settings → Long Slug**: every line of the status table should be green.
3. Optional: set the longest slug for new posts and terms.

= Coming from Long URL Maker =

1. Back up your database.
2. Install and activate Parich Long Slug while Long URL Maker is still active.
3. Deactivate Long URL Maker with the **Deactivate Long URL Maker safely** button in the notice. Do not use its usual Deactivate link: that runs its routine that rewrites every slug from the title.
4. Go to **Settings → Long Slug → Repair slugs**, click **Scan for damaged slugs**, check the proposals, then repair.
5. Clear any page cache and let your sitemap plugin rebuild its sitemap.

Do not activate Long URL Maker again. If someone does, this plugin switches it off at once, before it can restore old slugs. You can delete Long URL Maker after the repair; its backup table stays in your database until you remove it yourself.

== Frequently Asked Questions ==

= Why can't WordPress keep long slugs by itself? =

Its database schema says the slug columns hold 200 characters, and every database update applies the schema again. Plugins that widen the columns lose the fight at the next update, because plugins are not loaded while WordPress updates its database. The guard in `mu-plugins` is loaded even then.

= What does the guard file do? Can I delete it? =

It changes one thing: the size WordPress gives the two slug columns while updating its database. It is copied from this plugin, never downloaded. If you delete it, it is put back the next time an administrator opens the dashboard. If it cannot be written, long slugs still come back after an update from the plugin's copy, and the status table tells you.

= The block editor removes Thai vowels and tone marks from the slug field. =

The block editor's slug field removes every combining mark when it loses focus, so "ข่าว" becomes "ขาว". This plugin compares the slug with the title when the post is saved and puts the marks back when that is all that changed. A slug you typed differently is kept as you typed it.

= Which damage can the repair tool not undo? =

* A slug rewritten from the title, with no evidence of the original anywhere (no backup row, no SEO data, no link, no 404 log, no import): you type it yourself, or import a URL list from Search Console or your server logs.
* An old slug put back by Long URL Maker's restore: both were real addresses, so you choose.
* "-2", "-3"… slugs cut when the post was made: their address never changed, so lengthening them is offered but not pre-selected.

= What happens to the redirects if I deactivate or delete the plugin? =

They stop when the plugin is deactivated and are deleted with it. Download them first (**Repair slugs → Download the redirects (CSV)**) and add them to a redirect plugin. The Plugins screen reminds you while redirects exist.

= Will very long URLs cause problems? =

Some browsers, crawlers and sitemap tools refuse URLs longer than 2,048 characters. A long slug under a long parent page can get there; the status table lists such posts. The default length of 1,000 bytes keeps a post's URL well under that.

= What does uninstalling remove? =

The plugin's tables (the copy of long slugs, scan results, the repair log and the redirects) and its settings. It never shortens the slug columns or changes a slug. It removes the guard only when no slug is longer than 200 characters, because without it the next WordPress update would cut them. Long URL Maker's backup table and settings are left alone.

= Does it work on multisite? =

Not yet: network activation is refused.

= How can I support the plugin? =

The plugin is free, with every feature for everyone. If it is useful to you, you can [make a donation](https://buy.stripe.com/3cIaEW9699pf3dyeAfaMU00). Thank you!

== Screenshots ==

1. Settings: how long new slugs may be, a live preview of the slug a title gives, and the status of what keeps long slugs safe (slug columns, guard, copy of long slugs).
2. Repair slugs: the scan lists slugs that were cut or rewritten, the original it proposes and the evidence for it. Items marked "Ready" can be repaired in one go.
3. After a repair: each repair can be undone, and the old addresses redirect (301) to the repaired posts.

== Credits ==

The functions that reproduce the slugs Long URL Maker made are ported from [Long URL Maker](https://wordpress.org/plugins/long-url-maker/) 2.0.1 by kpcode, itself based on PP-auto-thai-url by Preukson (both GPLv2 or later), so the repair tool can recognise its slugs exactly.

== Changelog ==

= 0.1.0 =
* First release: long Thai-friendly slugs, the guard, the copy of long slugs, and the repair tool for Long URL Maker damage with redirects and undo.
