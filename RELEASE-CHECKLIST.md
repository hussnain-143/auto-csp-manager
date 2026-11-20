# Auto CSP Manager — Release Checklist

**Version:** 1.2.0
**Prepared:** 2026-11-19
**Status:** prepared, not published. No tag, no commit, no upload.

---

## 1. What is in this release

A presentation-layer release. The policy engine, the scanner, the report endpoint, the
header writer and the database schema are unchanged from 1.1.0.

* All administration screens redesigned against the approved brand identity.
* The old Overview screen split into **Dashboard** and **Settings**.
* Screens reordered to follow the job: Dashboard, Scanner, Policy, Reports, Settings.
* Status no longer conveyed by colour alone — every status carries a word and a shape.
* Policy editor groups its fourteen rules by how much damage getting them wrong does.
* Real scan progress, announced to screen readers.
* Proper empty states, an accessible confirmation dialog, and responsive layouts.
* Repository reorganised for release: `assets/js/`, `languages/`, `.gitignore`,
  `.distignore`, and a reproducible build script.

Full detail is in `readme.txt` (user-facing) and `AUDIT.md` Phase 6 (engineering).

## 2. Security review

Reviewed against the production source, not assumed from the previous report.

| Control | Finding |
| --- | --- |
| Capability checks | `manage_options` on all six menu pages, the report-action handler, the bulk handler and the AJAX stepper |
| Nonces | `check_admin_referer()` on all six `admin_post_*` handlers and both report paths; `check_ajax_referer()` on the scan stepper |
| SQL | 30 `$wpdb` call sites; every value goes through `prepare()`. Only table names (from `$wpdb->prefix`) and generated placeholder strings are interpolated |
| Output escaping | PHPCS reports zero `EscapeOutput` errors; every variable reaching the alert helper is escaped at the call site |
| REST endpoint | `permission_callback => __return_true` — required, since browsers post CSP reports unauthenticated. Hardened by a mode gate, a body-size cap, a per-client per-minute rate limit, JSON validation and a per-request report cap. Reports can never alter the policy |
| File operations | Theme scan skips symlinks, confines every path with `realpath()` under the theme root, caps file size at 2 MB and allows only a fixed extension list |
| SSRF | Crawl targets are built from the site's own permalinks, never from user input. `redirection => 0`, hops followed by hand, maximum 3, non-HTTP(S) schemes rejected, off-site redirects refused |
| CSP default | Ships as `Content-Security-Policy-Report-Only`. Verified on a clean install |
| Plugin screens | Always exempt from the policy, so an owner cannot lock themselves out |
| Uninstall | Drops the table, deletes all 8 options, clears transients and rate-limit rows, unschedules cron — per site on multisite. Verified empty afterwards |
| Multisite | Integration suite passes under `WP_MULTISITE=1` |

### Accepted risks

* **No private-IP deny-list on the crawler.** Deliberate, carried forward from the 1.1.0
  audit (finding F-H): the crawler only fetches the site's own permalinks, and a staging,
  Docker or intranet install legitimately lives on a private address.
* **`'unsafe-inline'` in the default policy.** Deliberate, documented in `readme.txt`:
  WordPress core, most themes and most plugins print inline code. Removing it by default
  would break most sites on activation.
* **The report endpoint is public.** Inherent to CSP reporting. Mitigations above.

## 3. Test results

Executed against the final source tree on 2026-11-19. Docker, `php:8.3-cli`,
MariaDB 10.11, WordPress 7.1.5.

| Check | Result |
| --- | --- |
| `php -l`, 25 files | **Pass** |
| PHPCS, WordPress standard, errors | **Pass — 0 errors**, 11 files |
| PHPUnit unit | **Pass — 131 tests, 267 assertions** |
| PHPUnit integration | **Pass — 45 tests, 153 assertions, 1 skipped** |
| PHPUnit integration, multisite | **Pass — 45 tests, 153 assertions, 1 skipped** |
| `node --check assets/js/admin.js` | **Pass** |
| `bash -n` on all three shell scripts | **Pass** |

The skipped test is `A foreign policy is detected and recorded`, which cannot run under
CLI. It was instead verified by hand against a live site — see below.

### Verified on a clean WordPress, installed from the built ZIP

Install, activate, dashboard, settings save, full AJAX scan, policy edit through to the
live response header, reports with row and bulk actions, deactivate, reactivate, and
uninstall with the table and all options confirmed gone. No PHP notices, warnings or
fatals were logged. No browser console errors. No failed network requests; all seven
plugin assets returned 200.

Responsive: zero horizontal overflow at 390 px, 768 px and 1440 px on all five screens.

## 4. The two previously outstanding manual checks

| Check | Result |
| --- | --- |
| Header collision with a host that sends its own CSP | **Pass.** With a second `Content-Security-Policy` injected ahead of the plugin, both headers appeared on the response, the plugin recorded the collision in `acsp_foreign_csp`, and the Dashboard showed the warning naming the offending header |
| Behaviour behind a full-page cache | **Ran, and it found a real limitation.** See below |

### Full-page cache: measured behaviour

Tested with WP Super Cache 1.12 in its default PHP caching mode.

* Cache **miss**: the response carried `Content-Security-Policy-Report-Only`.
* Cache **hit**: the response carried **no CSP header at all**.

WP Super Cache stores a snapshot of the response headers and replays it on a hit; that
snapshot did not include the CSP header. A cached page is served without running
WordPress, so any cache that does not store and replay the header will serve visitors
with no policy. Caches that write a plain HTML file served directly by the web server,
and most CDNs, behave the same way.

This is not a regression and not specific to 1.2.0 — it is inherent to page caching — but
it materially affects the protection a site actually receives, so `readme.txt` now states
it plainly instead of the previous, weaker wording about a "stale header". **No code change
was made for this**: the correct fix is to send the header from the web server or CDN, or
to integrate with individual cache plugins, and neither belongs in a packaging release.

**Recommended follow-up for a future version:** detect a known page cache on activation
and warn on the Dashboard, the same way a foreign CSP is already detected.

## 5. Packaging

Built by `bin/build-release.sh`, which copies an explicit allowlist rather than excluding
a denylist, so an unlisted new file fails the build instead of silently shipping.

The script refuses to continue unless: the plugin header, `ACSP_VERSION` and the readme
stable tag agree; a changelog entry exists for the version; every `require_once` in the
bootstrap resolves inside the package; every asset referenced by the code is present,
including those built at runtime by `brand_url()`; no development file is present; and
every staged PHP file parses. It refuses to overwrite an existing artefact without
`--force`.

See section 7 for the artefact details.

## 6. Installation

1. **Plugins → Add Plugin → Upload Plugin**, choose the ZIP, install, activate.
2. The plugin starts in **Report only** mode. Nothing on the site is blocked.
3. It opens on the Scanner and runs a first scan of the database and theme.
4. Use the site normally for a few days, then review **Reports** and decide each entry.
5. When no new reports arrive, switch the mode to **Enforce** on **Settings**.

Requires WordPress 5.8 or later and PHP 7.4 or later.

**If the site uses a full-page cache or a CDN, read section 4 before relying on the
policy.**

## 7. Rollback

The release changes no database schema, so rolling back is safe and needs no migration.
`ACSP_DB::DB_VERSION` is `'1'` in both 1.1.0 and 1.2.0.

* **Preferred:** Plugins → deactivate → delete, then upload the 1.1.0 ZIP and activate.
  Deactivating stops the header immediately; settings, policy and reports are preserved
  because they live in options and a table that deactivation does not touch.
* **Fastest mitigation, no rollback:** set the mode to **Report only**, or **Disabled**,
  on the Settings screen. This stops the policy from blocking anything within one page
  load and does not require touching files.
* **If the admin is unreachable:** rename or delete
  `wp-content/plugins/auto-csp-manager/` over SFTP. WordPress deactivates a plugin whose
  files have gone, and the header stops. Stored data survives for when it is restored.
* **Do not** use *uninstall* to roll back: `uninstall.php` drops the reports table and
  deletes every option, which discards the allow/block/ignore decisions.

Purge any page cache after a rollback, for the reason in section 4.

## 8. Outstanding before publishing

- [ ] **Initialise Git and make the first commit.** The project has no version control.
      `.gitignore` is in place and the tree is clean; this was left for review.
- [ ] Review and approve the staged structure and the built artefact.
- [ ] Confirm the WordPress.org assets (banner, icon, screenshots) separately — they live
      in the brand kit and are not part of the plugin ZIP.
- [ ] Decide whether the cache limitation in section 4 warrants a Dashboard warning before
      release, or is acceptable as a readme note for 1.2.0.

## 9. Sign-off

| | |
| --- | --- |
| Code review | Complete |
| Security review | Complete, with the accepted risks in section 2 |
| Automated tests | Complete, all passing |
| Live verification | Complete, from the built ZIP |
| Packaging | Complete, reproducible |
| Published | **No.** Awaiting approval |
