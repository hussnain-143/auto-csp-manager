=== Auto CSP Manager ===
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later

Builds a Content-Security-Policy for your site automatically, collects violation reports on a built-in endpoint, and lets you allow, block or ignore each reported source from wp-admin.

== Description ==

**On activation** the plugin scans your database and active theme and starts in Report-only mode, so nothing on the site can break. The policy it saves is the safe baseline: your own site, plus what WordPress itself needs. Everything else the scan found is listed on the Scanner screen for you to approve. Nothing third-party is added to your policy until you say so - a scan can see what your site loads, but it cannot tell a content delivery network from an intruder.

**Modes**

* Report only - nothing is blocked; violations are logged.
* Enforce - browsers block anything that is not in the policy; violations are still logged.
* Disabled - no header is sent.

**Reports**

Browsers send violation reports to the plugin's own endpoint:

    /wp-json/auto-csp-manager/v1/report

(or `/index.php?rest_route=/auto-csp-manager/v1/report` with plain permalinks). An external reporting URL can be used instead.

Each distinct source appears once on the Reports screen with a hit counter. For each one you can:

* Allow - add it to the policy.
* Block - keep it out of the policy; scans will never re-add it; attempts keep being counted.
* Ignore - leave the policy alone and never log it again.

If a wider rule in your policy already allows the source you are blocking - a wildcard such as `https://*.example.com`, or `https:` - the block cannot take effect on its own, because a Content-Security-Policy can only list what is allowed and has no way to express an exception. The plugin says so instead of pretending otherwise, and offers you the choice: narrow the wider rule to the specific addresses that are actually in use, or keep it and record the block as a note to yourself. A block that is only a note is labelled as such everywhere it appears.

**Scanner**

Re-scan at any time from the Scanner screen, either reviewing every proposed change or applying additions automatically. The scan covers:

* Database: post content, reusable blocks, templates, custom CSS, cached embeds, post meta containing markup (page builders), widgets, theme mods and options containing markup.
* Theme: PHP/HTML templates, CSS, JavaScript and theme.json of the active theme and its parent.
* Live pages: the home page, recent items of every public post type, an archive, search, and WooCommerce pages when present.

== Things to know ==

* The default policy includes 'unsafe-inline' for scripts and styles, because WordPress core, most themes and most plugins print inline code. This still restricts where code can be loaded from, but it is not a strict nonce-based policy.
* A scan cannot see sources that scripts load after the page has opened. Report-only mode exists to catch those: run in it for a few days before enforcing.
* The automatic scan allows whatever it finds. On a site that may have been compromised, review the results manually.
* Full-page caches and CDNs can drop the policy entirely. A cached page is served without running WordPress, so the header this plugin adds is only sent if the cache stored it and replays it, and not every cache does. This was measured with WP Super Cache 1.12 in its default PHP caching mode: the first visit to a page carried the policy, and every cached visit afterwards carried none at all. Caches that serve a plain HTML file through the web server, and most CDNs, behave the same way. If you use one, send the header from your web server or CDN instead of relying on this plugin alone, and check a cached page with your browser's network tab before you trust the policy. Changing the policy also needs a cache purge.
* If something else on your site already sends a Content-Security-Policy - your host, a reverse proxy, a security plugin or your theme - browsers apply both, and a resource has to be allowed by both. The stricter one always wins, and loosening the policy here cannot widen the other one. The plugin detects this and says so on the Dashboard screen.
* Reporting API headers (Reporting-Endpoints / Report-To) are only sent on HTTPS sites; `report-uri` is always sent.
* The report endpoint is public by necessity. It is size-limited, rate-limited per client in fixed one-minute windows, only accepts reports about this site's own pages, de-duplicates, and caps the table size. Reports are evidence, never permission: nothing arriving at that endpoint can change your policy. When the log is full, undecided entries that nobody has looked at for a day are recycled so that genuine violations are not lost behind a flood, and the screens tell you the log filled up.
* The scanner only ever fetches pages on your own site, and will not follow a redirect that leaves it. A site served from localhost or a private address - a staging, Docker or intranet install - is scanned normally.

== Developer hooks ==

* `acsp_send_header` (bool $send, string $context) - skip the header for a request. Context: front, admin, login.
* `acsp_crawl_urls` (array $urls) - change which URLs the scanner crawls (max 20).
* `acsp_companion_sources` (array $rules) - extra sources added when a known service is detected.
* `acsp_noise_schemes` (array $schemes) - URL schemes treated as browser-extension noise.
* `acsp_rate_limit` (int $per_minute) - reports accepted per client per minute; 0 disables.
* `acsp_client_ip` (string $ip) - the address used to group the rate limit. Defaults to REMOTE_ADDR, which a client cannot forge. Only return a forwarded address if you know your proxy is trusted; otherwise the limit can be bypassed with a header.

== Running the tests ==

Nothing needs to be installed on your machine except Docker:

    docker compose -f docker-compose.test.yml run --rm php bash bin/ci.sh

That runs a syntax check on every file, WordPress coding standards, the unit suite, and the
integration suite both as a single site and as multisite.

The unit suite needs no database and can be run on its own with any PHP 7.4 or later:

    composer install && composer test

== Changelog ==

= 1.2.0 =
* Redesigned every administration screen around the plugin's own visual identity, replacing styling that was borrowed wholesale from wp-admin.
* The Overview screen has been split in two. "Dashboard" now shows the protection status, the figures that matter and the quick actions; "Settings" is its own screen and holds the form that used to sit below the dashboard. The settings link on the Plugins screen points at the new Settings screen.
* Screens are now ordered by the job they do: Dashboard, Scanner, Policy, Reports, Settings.
* Status is no longer communicated by colour alone. Every status carries a word and a distinct shape, so it survives greyscale, colour blindness and a screen reader.
* The policy editor groups its fourteen rules by how much damage getting them wrong does, instead of presenting fourteen identical rows.
* A scan now shows real progress: a progress bar driven by the scan's own steps, and a running log that a screen reader announces as it goes.
* Every screen has a proper empty state, and the Reports screen explains what it is waiting for depending on which filter is in use.
* Destructive actions are confirmed in an accessible dialog instead of a browser alert box, and still fall back to one where the dialog element is unsupported.
* The admin menu icon is the plugin's own mark and now follows whichever admin colour scheme is in use, rather than being a fixed image that disappeared against half of them.
* The screens work on phones and tablets. Previously they had no responsive rules at all.
* Fixed: the status of a report could be clipped out of view in the violations table.
* Fixed: the "public website" row in the settings was drawn as an empty tick box, which read as "off" directly beside the words "always on".
* No change to the policy engine, the scanner, the report endpoint or the database.

= 1.1.0 =
* Blocking a source that a wider rule still allows is no longer reported as blocked. The plugin explains the conflict and offers to narrow the wider rule, or to record the block as a note.
* Activation and the first scan no longer add what they find to the policy on their own. Findings are listed for review.
* The scanner now checks HTTPS certificates when reading your own pages, retrying once without verification and warning you if that was necessary.
* The scanner follows redirects one at a time and refuses any that leave your site.
* Settings and widgets are now scanned in pages. Previously a site with more than a thousand matching entries had the rest skipped without saying so.
* A scan that could not cover the whole site now says which parts it missed, and will not apply its findings automatically.
* A resource on another port of your own hostname is recognised as a separate origin, as browsers treat it, and gets its own entry.
* Rate limiting now uses fixed one-minute windows and counts atomically. Previously a client reporting steadily could be shut out indefinitely.
* Violation reports are now stored in a single atomic statement, so two arriving at once can no longer lose one.
* When the report log is full, stale undecided entries are recycled instead of newer reports being dropped in silence, and the screens say the log filled up.
* Another component's Content-Security-Policy is detected and reported on the Overview screen.
* The theme scanner no longer follows symbolic links out of the theme directory.
* The policy editor now warns about entries that are weaker than they look, including stored nonces, `*` and `'strict-dynamic'`.
* Added the `acsp_client_ip` filter.
* Report URLs, policy sources and scanner redirects now reject input that was previously accepted in a mangled form: a URL containing a line break, a source containing a control character, a source list where a malformed entry left a bare "*" behind, and a redirect to a non-web address.
* A PHP fragment inside a script tag in a theme template is no longer mistaken for inline code, which could have a scan propose re-adding 'unsafe-inline'.
* Added a test suite: 131 unit tests for the policy engine, report handling and scanning, and 45 integration tests for storage, the endpoint, admin permissions, headers and the plugin lifecycle. Verified on PHP 7.4 through 8.3, single site and multisite.

= 1.0.0 =
* Initial release.
