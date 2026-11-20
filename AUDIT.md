# Auto CSP Manager — Security, Architecture and Quality Audit

Audited: 1.0.0 · Remediated and released as: 1.1.0 · Date: 2026-11-16

Phases 1-2 below were a read-only review pass; Phases 3-5 that follow are the remediation
and its validation.
Scope: all 11 PHP files (4,088 lines), `assets/admin.js` (186), `assets/admin.css` (152), `readme.txt`.
Method: full source read, call-site tracing, manual evaluation of the CSP engine against
CSP Level 3 matching semantics, then fixes validated by an executed test suite (Phase 5).

**Headline:** 1 High and 7 Medium findings confirmed and fixed, plus 11 further findings of
my own. No SQL injection, XSS, CSRF, missing capability check, nonce gap or header-injection
path was found. One previous-review finding was overstated and is corrected (F-F), and the
requested private-IP SSRF deny-list was deliberately not implemented, with reasons (F-H).

---

## 1. Architecture overview

Single-entry procedural bootstrap, then 9 classes, no namespaces, no autoloader, no
dependencies. Class-per-concern separation is clean and the dependency graph is almost a
DAG — worth preserving.

```
auto-csp-manager.php            constants, 7 require_once, activation/deactivation hooks
  └─ ACSP_Plugin  (singleton)   wiring, activation, ensure_setup, daily cron
       ├─ ACSP_DB               table install/migrate, report CRUD, decision -> policy
       ├─ ACSP_Policy           THE security boundary: source validation, header build
       ├─ ACSP_Settings         one option, mode + reporting config, URL sanitising
       ├─ ACSP_Scanner          DB + theme + crawl discovery, diff against policy
       ├─ ACSP_Reporter         public REST endpoint, untrusted-input normalisation
       ├─ ACSP_Headers          send_headers / admin_init / login_init output
       └─ ACSP_Admin (is_admin) 4 screens, admin_post_* handlers, wp_ajax scan stepper
            └─ ACSP_Reports_Table   WP_List_Table for the report queue
```

Dependency direction: `Admin -> {Scanner, Policy, DB, Settings}`, `Scanner -> {Policy, DB,
Reporter::site_hosts}`, `Reporter -> {Policy, DB, Settings}`, `Headers -> {Policy,
Settings}`, `Policy -> Settings`. `Scanner` reaching into `ACSP_Reporter::site_hosts()`
(scanner:76) is the one layering smell — host identity belongs in `ACSP_Policy` or a small
helper, not in the REST class.

### File responsibility map

| File | Lines | Responsibility | Security weight |
|---|---|---|---|
| `auto-csp-manager.php` | 33 | Constants, includes, hooks | Low |
| `includes/class-acsp-plugin.php` | 124 | Bootstrap, activation, cron | **Medium** (auto-trust on activation) |
| `includes/class-acsp-settings.php` | 129 | Settings option + report-URL sanitising | **High** (URL lands in a header) |
| `includes/class-acsp-policy.php` | 500 | Source validation, matching, header build | **Critical** |
| `includes/class-acsp-db.php` | 365 | Report table, decisions | **High** |
| `includes/class-acsp-scanner.php` | 1,194 | Discovery (DB/theme/crawl), diff | **High** (outbound HTTP, file I/O) |
| `includes/class-acsp-reporter.php` | 327 | Public REST endpoint | **Critical** (unauthenticated) |
| `includes/class-acsp-headers.php` | 83 | Header emission | **High** |
| `includes/class-acsp-admin.php` | 1,015 | UI, form handlers, AJAX | **High** (privileged actions) |
| `includes/class-acsp-reports-table.php` | 275 | Report list table | Medium (output escaping) |
| `uninstall.php` | 43 | Per-site cleanup, multisite loop | Low |

### Execution flows traced

1. **Activation** — `register_activation_hook` -> `ACSP_Plugin::activate()` (plugin.php:66)
   -> `ACSP_DB::install()` -> `initial_setup()` (plugin.php:78) runs a 20 s synchronous
   DB+theme scan -> `ACSP_Policy::from_findings()` -> `save()` -> `acsp_needs_full_scan=1`
   -> redirect transient.
2. **First admin visit** — `activation_redirect()` (admin.php:92) -> `?page=acsp-scanner&acsp_welcome=1`
   -> `assets()` sets `autoStart` (admin.php:123) -> `admin.js:178` calls `start(true)`
   -> AJAX `acsp_scan_step` loop -> `finish` step with `auto=1` (admin.php:985)
   -> `ACSP_Policy::add_sources(ACSP_Scanner::additions($result))`. **Unattended auto-approval.**
3. **Front-end request** — `send_headers` -> `ACSP_Headers::front()` -> `send('front')`
   -> `ACSP_Policy::build_headers()` -> `header($n.': '.$v, false)` (headers.php:80).
4. **Violation report** — browser POST -> `ACSP_Reporter::handle()` (reporter.php:52)
   -> mode/dest gate -> 64 KB cap -> `rate_limited()` -> `json_decode` -> `extract_reports()`
   -> `normalise()` (host check, noise filter, `blocked_to_source()`) -> `ACSP_DB::record()`.
5. **Decision** — `load-{reports}` -> `handle_report_actions()` (admin.php:588) -> cap check,
   `check_admin_referer`, `ACSP_DB::apply_action()` (db.php:295) -> `ACSP_Policy::add_source()`
   or `remove_sources()` + status write.
6. **Uninstall** — `uninstall.php` loops `get_sites()`, drops table, deletes options and
   `_transient_acsp_*` rows, clears cron.

### What is genuinely well built

Credit where due, so the remediation doesn't trample it:

- `ACSP_Policy::sanitize_source()` (policy.php:214) is a real allow-list: rejects
  whitespace, `"`, `;`, `,`, `<>`, backslash and `\x00-\x1f`, caps at 500 chars. I could not
  construct a header-injection payload that survives it.
- Defence in depth on header output: source validation, `sanitize_report_url()`
  (settings.php:103) rejecting `[\s;,"]`, and CR/LF stripping at `headers.php:78`.
- Every SQL write uses `prepare()`/`insert()`/`update()`; only table names interpolate.
  **No SQL injection found.**
- Every privileged handler checks `manage_options` **and** a nonce (`guard()` admin.php:139;
  `handle_report_actions` admin.php:589/600/614/628; `ajax_scan_step` admin.php:908-911).
  **No CSRF or missing-capability finding.**
- `apply_scan()` (admin.php:864) intersects POSTed sources against the *stored* diff, so a
  forged form cannot inject a source the scan never found. Good design.
- All admin output is escaped (`esc_html`/`esc_attr`/`esc_url`) including the attacker-
  influenced `blocked_uri`, `script_sample`, `document_uri` (reports-table.php:172-185, 232-258).
  **No XSS found.**
- `ACSP_Policy::covers()` correctly implements CSP 3 scheme relaxation (`http:` matches
  `https`, `*` excludes `data:`/`blob:`) and wildcard/port matching.
- Resource caps throughout: 5,000 files / 15 s / 2 MB per file (scanner:404-407), 1 MB per
  row, 100-row batches, 20 crawl URLs, 3 MB response cap, 64 KB request body.
- Purge only ever deletes `status='new'` (db.php:352) — admin decisions are never auto-erased.
- `wp_safe_redirect()` everywhere. **No open redirect.**
- Unsafe deserialization: the scanner reads option values as **strings** and regexes them.
  `maybe_unserialize()` is never called on scanned data. **No PHP object injection.**

---

## 2. Verification of the 7 pre-flagged issues

### Issue 1 — Wildcard block behaviour: **CONFIRMED. High.**

`ACSP_DB::apply_action()` case `'block'` (db.php:313-315):

```php
ACSP_Policy::remove_sources( array( $row->directive => array( $row->source ) ) );
return false !== $wpdb->update( $table, array( 'status' => 'blocked' ), ... );
```

`remove_sources()` (policy.php:296) does `array_diff` on **exact strings**. With
`script-src https://*.example.com` in the policy, blocking `https://malicious.example.com`
diffs nothing (`$removed` stays 0, no save), yet the status write runs unconditionally and
the row is marked **Blocked**. The origin is still fully permitted by the wildcard.

It compounds: `ACSP_Scanner::diff()` (scanner:205) uses `covers()`, so the host is not
offered as an addition, and scanner.php's "Found, but blocked by you" list (admin.php:850)
tells the admin it "stay[s] out of the policy". Three places assert a protection that does
not exist. This is the worst finding in the audit — not memory corruption, but a security
control that lies to the operator.

Same class of bug applies to a scheme source (`https:`) and to `'*'`.

### Issue 2 — `scan_options()` pagination: **CONFIRMED. Medium.**

`scanner.php:366-378`: one query, `LIMIT 1000`, **no `ORDER BY`, no `OFFSET`, no resume
cursor, and no warning emitted**. Options 1,001+ are silently skipped, and without
`ORDER BY` *which* 1,000 is non-deterministic across runs. `scan_posts()`/`scan_meta()`
are properly cursor-paginated, so this is an inconsistency, not a design choice.

Consequence: the scan reports success (`finish()` writes a clean result, scanner:157), the
admin enforces, and a widget on option #1,500 breaks. Your own requirement — an incomplete
scan must be distinguishable from a complete one — is not met. The theme scanner at least
sets a warning on truncation (scanner:410); the options scanner sets none.

### Issue 3 — TLS verification: **CONFIRMED. Low–Medium.**

`scanner.php:600`: `'sslverify' => apply_filters( 'https_local_ssl_verify', false )`.
Core's default for that filter is also `false`, so this matches WP convention for
self-requests — but the consequence here is specific: the crawl's output becomes **policy
content**. An on-path attacker or DNS poisoning between the server and its own hostname can
serve forged HTML and get an attacker origin written into `script-src`. It also masks
genuine certificate misconfiguration from the operator.

Exploitation requires network position — this is not a remote one-shot. But "verify by
default, retry once unverified and surface a loud warning" costs nothing and is strictly
better.

### Issue 4 — Origin comparison: **CONFIRMED. Medium (functional).**

`scanner.php:1033`: `if ( in_array( $host, $this->self_hosts, true ) ) return '';` —
**host only**, no port, no scheme. `site_hosts()` (reporter.php:230) returns bare hostnames.

So on a site at `https://example.com`, a resource at `https://example.com:8443/app.js` is
treated as same-origin and dropped from the policy. CSP `'self'` does **not** match a
different port, so enforcement blocks that resource with no corresponding scan finding.

Note the inconsistency: `ACSP_Reporter::blocked_to_source()` (reporter.php:319) *does* get
this right — `if ( ! $port && in_array( ... ) ) return "'self'";`. Two code paths, two
different notions of identity. Scheme is also ignored on both sides (`http://example.com`
on an HTTPS site), partly mitigated by the https upgrade at scanner:1043.

### Issue 5 — Automatic policy application: **CONFIRMED. Medium–High (design).**

Two auto-trust paths:

1. `ACSP_Plugin::initial_setup()` (plugin.php:78-90) — activation scans and **saves** a
   policy built from whatever it found, with no review.
2. `admin.js:178` -> `start(true)` -> `ajax_scan_step` `finish` with `auto=1`
   (admin.php:985) -> `add_sources(additions($result))`. The post-activation scan
   **auto-approves every discovered origin**, unattended. The admin never sees the list.

On a site that is already compromised — which is a very common reason someone installs a
CSP plugin — this bakes the attacker's C2 origin into `script-src` and then presents it as
a hardened policy. `readme.txt` warns about it in prose; the default code path does the
unsafe thing anyway. Report-only mode limits the blast radius (nothing is enforced yet),
which is why this is not Critical, but the default should be review-first.

### Issue 6 — Rate limiter: **CONFIRMED, plus a second bug the brief didn't name. Medium.**

`reporter.php:95-106`.

(a) **Non-atomic** get-then-set: concurrent requests read the same `$count` and all pass.
Real but low impact — the row cap bounds the damage.

(b) **The more serious one: the window never closes.** `set_transient($key, $count+1, MINUTE_IN_SECONDS)`
resets the TTL on *every* request. This is not a fixed window — any client sending at least
one report per 60 s keeps its own counter alive forever, so after 120 reports that client is
rate-limited **permanently** until it goes quiet for a full minute.

(c) The bucket key is `REMOTE_ADDR` (reporter.php:102). Behind a CDN, reverse proxy or load
balancer, *every* visitor shares one bucket — combine with (b) and violation reporting on a
busy site silently dies after the first 120 reports. The filter `acsp_rate_limit` exists as
an escape hatch, but the default behaviour is a reliability trap.

### Issue 7 — Existing CSP header compatibility: **CONFIRMED. Medium.**

`headers.php:80`: `header( $name . ': ' . $value, false )` — `$replace = false`, so the
plugin **appends**. If the host, a reverse proxy, a security plugin or the theme already
sent a CSP, the browser receives two policies and enforces the **intersection** of both.
Every directive in both must allow a resource for it to load.

Practical result: the site breaks in ways the admin cannot fix from this plugin's Policy
screen, because loosening *this* policy cannot widen the *other* one. Detection exists but
is weak and late — `crawl_url()` (scanner:614-621) warns only if a crawl happens to observe
a foreign header; nothing checks at header-send time, and the Overview screen never says
"another policy is active".

---

## 3. Additional findings (mine, not in the brief)

### F-A — Report-table flooding by an unauthenticated attacker. **Medium. Confirmed.**

`ACSP_DB::record()` (db.php:165-170): when `COUNT(*) >= max_rows` (default 2,000) every new
report is dropped (`return 'full'`). `document_uri` is attacker-supplied JSON and only
checked against the site host (reporter.php:196) — trivially forged. At the default 120
reports/min with unique `blocked_uri` values, an attacker fills 2,000 rows in ~17 minutes.

Impact: **genuine violations are silently dropped from then on**, and the review queue is
poisoned with attacker-chosen entries designed to be mistaken for legitimate CDNs. It
cannot change the policy — `allow` is admin-only and nonce-protected — so this is an
availability/integrity-of-evidence issue, not privilege escalation. Needs a per-source cap,
eviction of low-hit `new` rows, and a "log is full" admin notice (today it fails silently).

Also: `COUNT(*)` runs on **every** inserted report. Perf cost on busy sites.

### F-B — Static nonces accepted into a persisted policy. **Low–Medium. Design.**

`sanitize_source()` (policy.php:230) accepts `'nonce-...'`, and `save()` persists it in an
option. Nothing in the plugin generates a per-request nonce. A nonce that is constant across
responses is **equivalent to `'unsafe-inline'`** for any attacker who can read the page —
worse, because it looks hardened. Either reject `nonce-` in the editor or warn explicitly.

### F-C — `'strict-dynamic'` in `keywords()` (policy.php:91). **Informational.**

An admin who adds it causes browsers to **ignore every host-source** in `script-src`. For a
plugin whose whole value is a host allow-list, that silently neuters the policy. Needs a
warning in the editor.

### F-D — `ensure_setup()` runs a 20 s sync scan on `admin_init`. **Low.**

`plugin.php:95-105` -> `initial_setup()` -> `run_sync(false, 20)`. On a network-activated
site, the first admin page load by any `manage_options` user can block for 20 seconds. Happens
once, but it is the slowest path in the plugin and it is on a user-facing request.

### F-E — No "scan was incomplete" state. **Medium. Reliability.**

The stored result (`scanner.php:150-160`) carries `stats.warnings` but no machine-readable
completeness flag. Truncated theme scan -> a warning string; truncated options scan -> nothing
(F/Issue 2); crawl skipped because the site cannot reach itself -> `stats['crawled']` false, which
*is* surfaced (admin.php:768). Inconsistent. A `complete => bool` + `skipped => []` in the
result, gating the "Scan and update automatically" button, is the clean fix.

### F-F — Unindexable `LIKE '%<script%'` scans. **Low. Performance. (Downgraded — see below.)**

`scan_meta()` (scanner:323-337) and `scan_options()` filter on `meta_value LIKE '%<script%'`.
A leading wildcard cannot use an index, so the value test is evaluated row by row.

**Correction to my first reading.** I initially wrote that `scan_meta()` re-runs a full table
scan on *every* 100-row batch. That is wrong, and worth spelling out because it changes the
remediation. The query is `WHERE meta_id > :cursor AND (…) ORDER BY meta_id ASC LIMIT 100`,
so the optimiser walks the primary-key index forward from the cursor and stops once it has
100 matching rows. Across a complete scan the table is therefore walked **once in total**,
not once per batch. The cost is real but bounded, and it is paid in small resumable slices.

The genuine problem in this area was `scan_options()`'s missing pagination (Issue 2), not the
predicate. Moving the filter into PHP would have been a net loss: it would pull every option
row — some of them megabytes — into PHP memory just to discard most of them. Server-side
filtering plus cursor pagination is the right combination, and that is what was implemented.

### F-G — Theme scanner follows symlinks. **Low.**

`RecursiveDirectoryIterator` (scanner:409) is created without
`FilesystemIterator::FOLLOW_SYMLINKS` being *disabled* — PHP follows symlinked directories
by default in recursive iteration. A symlink inside the theme pointing outside it walks out
of the theme tree (bounded by the 5,000-file / 15 s caps). Reachable only by someone who can
already write to the theme directory, and the contents are never echoed — only URLs are
extracted. Still worth a `$file->isLink()` skip plus a `realpath()` containment assertion.
Flagged from code reading; not runtime-verified.

### F-H — Crawler SSRF: **NOT CONFIRMED as attacker-controllable.** Low residual.

I traced this carefully because the brief asks for it. `crawl_targets()` (scanner:539-586)
builds URLs exclusively from `home_url()`, `get_permalink()`, `get_option('page_for_posts')`,
`get_term_link()` and `wc_get_page_permalink()`. `ajax_scan_step` takes only `step`
(`sanitize_key`) and `cursor` (`absint`) from POST, and `cursor` **indexes into the
server-built array** (admin.php:964-979). No request parameter reaches the fetch. The
`acsp_crawl_urls` filter is code-level, i.e. already-trusted.

Residual risk, genuinely present: `'redirection' => 3` (scanner:596) follows redirects
**without re-validating the destination**. If any crawled URL redirects to an internal host,
the fetch follows it.

**Push-back on the brief:** a blanket private-IP/localhost deny-list would be wrong here.
This plugin legitimately crawls *its own site*, which on staging, Docker, LAN and intranet
installs **is** `127.0.0.1` or a private address. Blocking those breaks the product for a
large, legitimate population. The correct control is **origin pinning**: allow the site's own
origin unconditionally, and validate every *redirect* destination against that origin —
rejecting link-local/metadata (`169.254.169.254`, `fd00::/8`) even then.

### F-I — Duplicate-insert race. **Low.**

`record()` (db.php:150-175) does `SELECT ... WHERE hash` then `INSERT`. Two concurrent
reports for the same new pair both pass the SELECT; the `UNIQUE KEY hash` makes the second
`INSERT` fail and `record()` returns `'error'` — the hit is lost, silently. An `INSERT ...
ON DUPLICATE KEY UPDATE hits = hits + 1` collapses the whole function into one atomic
statement and fixes F-A's double query too.

### F-J — Two queries per duplicate report. **Low. Performance.**

`db.php:155-162`: `$wpdb->update()` for the sample fields, then a second
`UPDATE ... hits = hits + 1`. Same row, same request. Merge them.

### F-K — No `load_plugin_textdomain()`. **Informational.**

Fine for a wordpress.org-hosted plugin (WP 4.6+ auto-loads), a problem for anyone shipping
it privately with bundled translations. Worth one line.

### F-L — Multisite review. **No finding.**

`maybe_install()` on `plugins_loaded` with a per-site `acsp_db_version` correctly covers
sites added to a network later. `uninstall.php` loops `get_sites(['number' => 0])`. Options
are per-site, so no cross-site privilege path. `ACSP_Policy::save()` uses `autoload = true`
— correct, the policy is needed on every front-end request.

---

## 4. Findings table

| ID | Issue | File / line | Severity | Class | Evidence | Fix direction |
|---|---|---|---|---|---|---|
| I-1 | Block marks a source blocked while a wildcard still permits it | `class-acsp-db.php:313`, `class-acsp-policy.php:296` | **High** | Confirmed security-control failure | `array_diff` on exact strings; status written unconditionally | Detect covering entries; offer narrow-the-wildcard or refuse + warn; never claim "blocked" otherwise |
| I-2 | `scan_options()` fixed `LIMIT 1000`, no pagination/ORDER BY/warning | `class-acsp-scanner.php:366-378` | Medium | Confirmed bug | No OFFSET or cursor; `finish()` reports success regardless | Cursor-paginate on `option_id`; mark result incomplete if truncated |
| I-3 | TLS verification off by default on the crawl | `class-acsp-scanner.php:600` | Medium | Confirmed weakness | `sslverify => apply_filters('https_local_ssl_verify', false)`; crawl output becomes policy | Verify by default; one unverified retry + loud warning |
| I-4 | Same-origin test compares host only, ignores port and scheme | `class-acsp-scanner.php:1033` | Medium | Confirmed bug | `in_array($host, $self_hosts)`; cf. correct check at `class-acsp-reporter.php:319` | Compare full origin; share one helper between scanner and reporter |
| I-5 | Activation + welcome scan auto-approve every discovered origin | `class-acsp-plugin.php:78`, `class-acsp-admin.php:985`, `assets/admin.js:178` | Medium-High | Confirmed design flaw | `start(true)` -> `add_sources(additions(...))`, unattended | Default to review-first; auto-apply only on explicit opt-in |
| I-6 | Rate limiter: non-atomic, TTL resets (window never closes), per-`REMOTE_ADDR` | `class-acsp-reporter.php:95-106` | Medium | Confirmed bug | `set_transient` on every hit; shared bucket behind proxies | Atomic fixed window (single SQL upsert); align TTL to window start |
| I-7 | Appends its CSP instead of replacing; foreign policies intersect | `class-acsp-headers.php:80` | Medium | Confirmed compatibility flaw | `header(..., false)`; detection only during crawl | Detect foreign CSP, surface it in Overview, offer replace-vs-append |
| F-A | Unauthenticated report flooding fills the table; later reports dropped silently | `class-acsp-db.php:165-170`, `class-acsp-reporter.php:196` | Medium | Confirmed vuln (availability) | 2,000-row cap + forgeable `document_uri`; ~17 min to fill | Per-source cap, evict low-hit `new` rows, "log full" notice |
| F-B | Static `nonce-` sources accepted into a persisted policy | `class-acsp-policy.php:230` | Low-Medium | Design weakness | Nothing generates per-request nonces | Reject `nonce-` in the editor, or warn explicitly |
| F-C | `'strict-dynamic'` silently neuters host allow-lists | `class-acsp-policy.php:91` | Informational | Design weakness | Browsers ignore host sources when present | Warn in the policy editor |
| F-D | 20 s synchronous scan on `admin_init` | `class-acsp-plugin.php:95-105` | Low | Performance | `run_sync(false, 20)` on a user-facing request | Defer to cron / async first step |
| F-E | Incomplete scan indistinguishable from complete | `class-acsp-scanner.php:150-160` | Medium | Reliability | No completeness flag; options truncation silent | `complete` + `skipped[]` in the result; gate auto-apply on it |
| F-F | Leading-wildcard `LIKE` on scanned tables | `class-acsp-scanner.php:323-337` | Low | Performance | Cursor + `ORDER BY id` + `LIMIT` means one table walk per scan, not per batch (first reading corrected) | Keep server-side filtering; the fix that mattered was pagination (Issue 2) |
| F-G | Theme walk follows symlinks out of the theme tree | `class-acsp-scanner.php:409` | Low | Potential (not runtime-verified) | No `isLink()` skip, no realpath containment | Skip links; assert containment |
| F-H | Redirects followed without destination re-validation | `class-acsp-scanner.php:596` | Low | Residual (not attacker-controllable) | No request param reaches the fetch; `redirection => 3` | Pin to the site's own origin; validate each hop; deny link-local/metadata |
| F-I | Duplicate-insert race loses a report | `class-acsp-db.php:150-175` | Low | Confirmed bug | SELECT-then-INSERT; unique key makes the loser return `'error'` | `INSERT ... ON DUPLICATE KEY UPDATE` |
| F-J | Two UPDATEs per duplicate report | `class-acsp-db.php:155-162` | Low | Performance | Same row, same request | Merge into one statement |
| F-K | No `load_plugin_textdomain()` | `auto-csp-manager.php` | Informational | Compatibility | Fine on w.org, breaks private distribution | One line on `init` |

### Explicitly checked, no finding

SQL injection · XSS (admin screens, incl. attacker-influenced report fields) · CSRF ·
missing capability checks · missing nonces · arbitrary option modification · PHP object
injection / unsafe deserialization · path traversal in request handling · open redirect ·
HTTP header injection (three independent defences) · attacker-controlled SSRF destination ·
policy modification via the REST endpoint · multisite privilege escalation · AJAX authz.

---

## 5. `unsafe-inline` assessment

Baseline ships `'unsafe-inline'` for `script-src` and `style-src` (policy.php:104-105).
That is a correct 1.0 decision and should **not** be flipped by default. Why it is needed:
core prints inline JS on nearly every page (`wp_localize_script` output, emoji settings,
`wp-includes/js/wp-emoji-loader`, the block editor's `wp-settings` inline data, admin-bar
JS, `wp_add_inline_script` from countless plugins) and inline CSS (`wp_add_inline_style`,
Customizer output, block-support styles, `wp_custom_css_cb`).

Nonce feasibility: WordPress has **no hook that covers every inline emission**. Nonces work
only for `<script>` tags routed through `wp_print_scripts`/`wp_add_inline_script`; they miss
inline `on*=` handlers (which need `'unsafe-hashes'`), `<style>` blocks from arbitrary
`wp_head` callbacks, and anything a page builder echoes directly. A nonce policy also
requires `Cache-Control: private` or nonce-aware cache varying — full-page caches and CDNs
serve a stale nonce, which breaks the site hard. Hashes are worse: they change whenever
inline data changes (cart counts, nonces, user IDs), so every dynamic inline script would
need a per-response hash computed after rendering, i.e. output buffering the entire page.

Recommended strategy (post-1.0, opt-in, explicitly **not** part of this remediation):
report-only nonce trial behind a setting, output-buffer the page, inject nonces into
`<script>` tags the plugin itself can see, keep `'unsafe-inline'` in the enforced policy
until the trial reports zero inline violations, and refuse to enable it when a page cache is
detected. Trading a working site for a theoretically stricter header is the wrong trade.

---

## 6. Validation capability

At the time of the audit this machine had no PHP, Composer or MySQL, and a source build was
abandoned. Validation instead ran in Docker, which needs nothing installed on the host:

    docker compose -f docker-compose.test.yml run --rm php bash bin/ci.sh

See **Phase 5** below for what that run actually produced.

---

# Phase 3 & 4 — Remediation (implemented)

Decisions taken by the maintainer before implementation: narrow-the-wildcard with an
explicit choice for I-1; review-first default for I-5.

Version bumped 1.0.0 → 1.1.0. Architecture unchanged: no new classes, no new files in
`includes/`, no renames, no schema change.

## 1. I-1 — Block that only looked like a block (**High**)

**What was wrong.** `ACSP_DB::apply_action()` case `'block'` called
`ACSP_Policy::remove_sources()`, which does `array_diff` on exact strings, and then wrote
`status = 'blocked'` **unconditionally**. With `https://*.example.com` in the policy,
blocking `https://malicious.example.com` removed nothing and the UI reported it as blocked.

**Why it was wrong.** It is a security control reporting a state the browser is not in. An
operator who blocks a host and sees "Blocked" stops investigating. Two further surfaces
repeated the claim: `ACSP_Scanner::diff()` stopped offering the host (because `covers()`
said it was already permitted), and the Scanner screen listed it under "Found, but blocked
by you… stays out of the policy".

**What changed.**
- `ACSP_Policy::covering_sources( $list, $source, $include_exact )`
  (`includes/class-acsp-policy.php:375`) — the matching engine now returns *which* entries
  permit a source. `covers()` is a one-line wrapper over it, so no caller changed behaviour.
- `ACSP_Policy::replace_source( $directive, $old, $new[] )` (`:326`) — swaps one entry for a
  set of narrower ones in a single `save()`, so the policy is never persisted half-narrowed.
- `ACSP_DB::apply_action( $id, $action, &$conflict )` (`includes/class-acsp-db.php:418`) —
  `'block'` now computes `covering_sources(..., false)` first. If anything broader is found
  it fills `$conflict` with `{ id, directive, source, covered_by[] }`, returns `false`, and
  **leaves the row undecided**. A new `'block_advisory'` action records the owner's explicit
  decision to keep the broader rule.
- Advisory blocks are stored in the `acsp_advisory_blocks` option (`set_advisory()`,
  `advisory_for()`, `advisory_count()`), **not a new column** — no migration needed. Cleared
  automatically by allow/ignore/reset and by a successful real block.
- `ACSP_Admin::narrowing_plan()`, `narrow_block()`, `block_advisory()`,
  `block_conflict_panel()` (`includes/class-acsp-admin.php`) — the conflict panel shows the
  exact replacement set *before* anything changes, because narrowing `*` or `https:` is a
  large change the owner must see. Both choices are separate nonce-protected
  `admin_post_*` handlers.
- `ACSP_Reports_Table::column_default()` labels an advisory block
  "Not enforced: still allowed by `…`", and a standing admin notice counts them.

**Why it is safe.** The default path is now *refuse and explain*; nothing is silently
narrowed. Narrowing uses only origins already known to be in use (last scan findings plus
sources the owner previously allowed), and the panel states that a scan cannot see
everything and that report-only mode should be re-run afterwards.

**Tests.** `tests/unit/PolicyCoversTest.php::test_wildcard_still_permits_a_removed_host`,
`::test_covering_sources_names_the_broader_entries`, `::test_replace_source_narrows_a_wildcard`;
`tests/integration/ReportStorageTest.php::test_block_is_refused_when_a_wildcard_still_allows_it`,
`::test_advisory_block_is_recorded_as_such`;
`tests/integration/AdminActionsTest.php::test_narrow_block_makes_the_block_real`.

## 2. I-5 — Unattended auto-approval (**Medium-High**)

**What was wrong.** `initial_setup()` built the policy with `from_findings()`, and the
post-activation welcome scan ran `start( true )` → `add_sources( additions( $result ) )`,
approving every discovered origin with no review.

**Why it was wrong.** A CSP plugin is very often installed *because* a site is misbehaving.
A scan sees what the site currently loads; it cannot distinguish a CDN from an intruder's
host. The default path therefore wrote the attacker's origin into `script-src` and presented
the result as hardened.

**What changed.** `ACSP_Plugin::initial_setup( $with_scan = true )` still scans — the
findings are what the review queue needs — but saves `ACSP_Policy::baseline()` only
(`includes/class-acsp-plugin.php:92`). `assets/admin.js:178` now calls `start( false )`.
Auto-apply remains available as an explicit button.

**Why it is safe.** `'self'` plus the baseline schemes still covers first-party resources,
and the plugin still starts in report-only, so nothing breaks while the owner reviews.

**Tests.** `tests/integration/AdminActionsTest.php::test_activation_does_not_auto_allow_discovered_sources`
(creates a post loading a third-party script, activates, asserts it is *not* in the policy
but *is* in the review queue).

## 3. I-6 — Rate limiter (**Medium**)

**What was wrong.** Three things, only one of which was in the brief: (a) read-then-write was
not atomic; (b) `set_transient( $key, $count+1, MINUTE_IN_SECONDS )` pushed the expiry
forward on every request, so a client reporting at least once a minute never got its
allowance back and was shut out indefinitely; (c) the bucket is `REMOTE_ADDR`, so behind a
CDN every visitor shares one — which, combined with (b), silently killed reporting on a busy
site after the first 120 reports.

**What changed** (`includes/class-acsp-reporter.php:90`). The window number is now part of
the key (`acsp_rl_<hash>_<window>`), making it a true fixed window — the TTL can no longer
extend it. `count_request()` increments atomically: `wp_cache_incr()` when an external object
cache is present, otherwise a direct `INSERT` (claim) falling back to
`UPDATE … option_value = option_value + 1` (increment). `sweep_counters()` clears past
windows opportunistically, so `wp_options` does not accumulate rows. A new `acsp_client_ip`
filter lets a site behind a trusted proxy supply the real address.

**One correction made during implementation.** My first version used `add_option()` for the
claim. That is wrong: core implements it as `INSERT … ON DUPLICATE KEY UPDATE option_value =
VALUES(option_value)` behind a cache-based existence check, so when that check loses a race —
precisely the case this code exists to handle — the counter is **reset to 1** and the limit
stops working. Replaced with a direct `INSERT` and suppressed errors, where a duplicate key
genuinely fails and falls through to the increment.

**Why the default stays `REMOTE_ADDR`.** It is the only value a client cannot forge. Reading
`X-Forwarded-For` by default would make the limit bypassable with one header — strictly worse
than a shared bucket. The filter puts that decision with whoever knows the proxy topology.

**Tests.** `tests/integration/ReportEndpointTest.php::test_rate_limit_is_a_fixed_window`,
`::test_rate_limit_is_per_client_and_stores_no_address`, `::test_rate_limit_can_be_disabled`.

## 4. F-A / F-I / F-J — Report storage (**Medium / Low / Low**)

**What was wrong.** `record()` did `SELECT` → `UPDATE` → second `UPDATE` (or `INSERT`), with
a `COUNT(*)` on every new row. Two concurrent reports for the same new pair both passed the
`SELECT`; the loser hit the unique index and the hit was lost. When
`COUNT(*) >= max_rows` the table froze and **every** genuine violation after that was dropped
silently — reachable by an unauthenticated attacker in ~17 minutes at the default limits,
since `document_uri` is attacker-supplied and only checked against the site host.

**What changed** (`includes/class-acsp-db.php:113`). One atomic
`INSERT … ON DUPLICATE KEY UPDATE hits = hits + IF( status = 'ignored', 0, 1 ), …`, with
every other column wrapped in the same `IF` so an ignored rule is left completely untouched.
Return value is derived from affected rows (1 = inserted, 2 = updated, 0 = ignored). The
row count is cached in a transient (`row_count()`/`bump_count()`), so the common path costs
no `COUNT(*)`. `make_room()` turns the log into a rolling window: one undecided, single-hit
row untouched for over a day is recycled to admit a new report. Rows the owner decided on,
rows seen more than once, and recent rows are never recycled, so a flood cannot push real
evidence out. When nothing can safely be recycled, `acsp_log_full` is set and the admin
screens say so instead of losing reports in silence.

**Tests.** `tests/integration/ReportStorageTest.php::test_reports_are_deduplicated`,
`::test_ignored_rows_are_left_alone`,
`::test_full_log_recycles_stale_rows_and_reports_itself`,
`::test_decisions_survive_purge_and_eviction`.

## 5. I-2 / F-E — Scanner pagination and completeness (**Medium**)

**What was wrong.** `scan_options()` was a single `LIMIT 1000` with no `ORDER BY`, no
`OFFSET` and no cursor: options past the first thousand were skipped, a different arbitrary
thousand each run, and the scan still reported success. More broadly there was no way to tell
an incomplete scan from a complete one, yet that difference decides whether it is safe to
enforce.

**What changed.** `scan_options( $after_id, $deadline )`
(`includes/class-acsp-scanner.php`) is now cursor-paginated on `option_id` in batches of
100, driven by `run_sync()` and by a resumable `'options'` AJAX step. `stats['incomplete']`
and `stats['skipped'][]` were added, set by a new `skip()` helper from every truncation path
(posts, meta, options, theme, crawl), with `ACSP_Scanner::is_complete()` for callers. The
Scanner screen names what was missed, and **auto-apply now refuses to run on an incomplete
scan** (`acsp_msg=scan_incomplete`).

**Deliberate non-change.** I did *not* move the `option_value LIKE '%<script%'` filter into
PHP as my own first draft of the audit suggested. That would pull every option — some
megabytes — into PHP to discard most of it. See the F-F correction above: server-side
filtering plus cursor pagination is the right combination.

**Tests.** `tests/unit/ScannerExtractionTest.php::test_completeness_is_reported`,
`::test_skipping_the_crawl_marks_the_scan_incomplete`.

## 6. I-4 — Origin comparison (**Medium**)

**What was wrong.** `ACSP_Scanner::url_to_source()` compared **hostnames**
(`in_array( $host, $this->self_hosts )`), so `https://example.com:8443/app.js` on a site at
`example.com` was treated as same-site and dropped — but CSP `'self'` matches scheme, host
**and** port, so enforcement blocked it with no scan finding to explain why.
`ACSP_Reporter::blocked_to_source()` got this half-right, giving two different notions of
identity in one plugin.

**What changed.** `ACSP_Reporter::site_origins()` and `normalise_origin()` (with default-port
folding) are the single source of truth; the scanner holds `$self_origins` and compares full
origins; `blocked_to_source()` uses the same helpers.

**Tests.** `tests/unit/ReporterNormaliseTest.php::test_other_port_on_same_host_is_not_self`,
`::test_normalise_origin`; `tests/unit/ScannerExtractionTest.php::test_same_origin_handling`.

## 7. I-3 / F-H / F-G — Crawler (**Medium / Low / Low**)

**What was wrong.** `sslverify => apply_filters( 'https_local_ssl_verify', false )` — the
crawl's output becomes policy, so an on-path attacker could get an origin written into
`script-src`, and genuine certificate misconfiguration was hidden. `redirection => 3`
followed redirects with no visibility of the destinations. The theme walk followed symlinked
directories out of the theme tree.

**What changed.** `sslverify => true` by default; on a TLS-specific failure
(`is_tls_error()`) the request is retried **once** unverified and a prominent warning is
recorded, so a self-signed staging site still scans but the owner knows the result cannot be
fully trusted. `redirection => 0` plus a manual `fetch()` loop (max 3 hops) where every hop
goes through `resolve_hop()` and must resolve to one of the site's own origins. The theme
walk skips symlinks and asserts `realpath()` containment under the theme root.

**Push-back recorded.** The brief asked for a private-IP/localhost deny-list. I did not
implement one, deliberately: a WordPress site legitimately lives on `127.0.0.1`, a `.local`
name or a private address (Docker, staging, LAN, intranet), so a blanket block would break
the plugin for a large, legitimate population while adding nothing — the crawl targets come
from `home_url()`/`get_permalink()`, never from a request, which I verified by tracing
`ajax_scan_step` (only `step` and an `absint`'d `cursor` index into a server-built array).
Origin pinning is the control that actually matches the threat, and it covers metadata and
internal addresses as a consequence.

**Tests.** `tests/unit/CrawlerRedirectTest.php` — 12 refused destinations (including
`169.254.169.254`, loopback, `[::1]`, another port, subdomain, suffix lookalike,
protocol-relative), same-origin hops followed, a localhost site still able to crawl itself,
and TLS-error recognition.

## 8. I-7 — Foreign CSP (**Medium**)

**What was wrong.** `header( $name . ': ' . $value, false )` appended. A policy from the
host, a proxy, a security plugin or the theme therefore intersected with this one — browsers
enforce both — breaking the site in a way the owner could not fix from the Policy screen.
Detection existed only if a crawl happened to observe it.

**What changed.** `ACSP_Headers::foreign_policies()` inspects `headers_list()` at send time
and records what it finds in `acsp_foreign_csp`; the Overview screen explains the collision
and that the stricter policy always wins. The `$replace` argument is now deliberate: `true`
for the plugin's own header (two hooks on one request must not emit two policies), `false`
when a foreign policy is present, so someone else's policy is never silently discarded.

**Known limit, documented:** a header added by the web server *after* PHP returns is
invisible to `headers_list()`; the crawl-time warning still covers that case.

**Tests.** `tests/integration/HeadersTest.php::test_a_foreign_policy_is_detected_and_recorded`,
`::test_headers_follow_the_mode`, `::test_send_can_be_filtered_off`.

## 9. F-B / F-C / F-D / F-K — smaller items

- **F-B/F-C** `describe_source()` now marks `*`, `https:`, `http:`, `'strict-dynamic'` and
  any `'nonce-…'` as risky with a plain-language explanation, and the policy editor renders
  those warnings per row. A stored nonce is fixed across responses and therefore reusable by
  anyone who reads the page — no stronger than `'unsafe-inline'`, while looking stronger.
- **F-D** `ensure_setup()` no longer runs a 20-second synchronous scan on `admin_init`; it
  saves the baseline (instant) and leaves scanning to the Scanner screen.
- **F-K** `load_plugin_textdomain()` added on `init`.
- `uninstall.php` extended for `acsp_log_full`, `acsp_advisory_blocks`, `acsp_foreign_csp`
  and `acsp_rl_%` counters.

## 10. `unsafe-inline`: deliberately unchanged

Left in the baseline, for the reasons in §5 above. A nonce migration needs output buffering
of the whole page, cannot reach every inline emission (no WordPress hook covers them all),
and breaks behind full-page caches. The staged, opt-in path is documented but out of scope
for a remediation release. Instead, the editor now tells the truth about what the entry costs.

---

# Files modified

| File | Change |
|---|---|
| `auto-csp-manager.php` | Version 1.1.0; `load_plugin_textdomain()` |
| `includes/class-acsp-policy.php` | `covering_sources()`, `replace_source()`, `covers()` wrapper, risky-source descriptions |
| `includes/class-acsp-db.php` | Atomic upsert `record()`, `row_count()`/`bump_count()`/`make_room()`/`full_since()`, advisory store, conflict-aware `apply_action()`, count maintenance in purge/clear/delete |
| `includes/class-acsp-reporter.php` | Fixed-window atomic rate limiter, `count_request()`, `sweep_counters()`, `site_origins()`, `normalise_origin()`, origin-aware `blocked_to_source()`, `acsp_client_ip` filter |
| `includes/class-acsp-scanner.php` | Paginated `scan_options()`, `skip()`/`is_complete()`, full-origin identity, TLS verification + retry, `fetch()`/`resolve_hop()`/`is_tls_error()`, symlink containment |
| `includes/class-acsp-headers.php` | `foreign_policies()`, deliberate `$replace`, foreign-CSP recording |
| `includes/class-acsp-admin.php` | Conflict flow (single + bulk), `narrowing_plan()`, `narrow_block()`, `block_advisory()`, `block_conflict_panel()`, paginated options step, auto-apply gating, incomplete-scan and foreign-CSP notices, risky-entry warnings, new messages |
| `includes/class-acsp-reports-table.php` | Advisory-block labelling |
| `assets/admin.js` | Welcome scan is review-first |
| `assets/admin.css` | Conflict panel styles |
| `uninstall.php` | New options and rate-limit counters |
| `readme.txt` | Behaviour changes, new filter, 1.1.0 changelog |

**New files:** `composer.json`, `phpunit.xml.dist`, `phpcs.xml.dist`, `tests/bootstrap.php`,
`tests/stubs/wp-stubs.php`, 6 unit suites, 5 integration suites, `AUDIT.md`.

# Database changes

**None.** No schema change, no migration. `DB_VERSION` is unchanged at `'1'` because the
table definition is untouched — advisory blocks, the full-log flag and the cached row count
are options/transients precisely to avoid a migration. New state introduced:

| Key | Kind | Autoload | Purpose |
|---|---|---|---|
| `acsp_advisory_blocks` | option | no | hash → covering entries for blocks that are not enforced |
| `acsp_log_full` | option | no | timestamp the log last turned a report away |
| `acsp_foreign_csp` | option | no | foreign CSP header names observed |
| `acsp_row_count` | transient | — | cached report count (5 min) |
| `acsp_rl_<hash>_<window>` | option | no | rate-limit counters, swept automatically |

All five are removed on uninstall.

# Phase 5 — Validation (executed)

Run in Docker, since this machine has no PHP. `docker compose -f docker-compose.test.yml
run --rm php bash bin/ci.sh` reproduces everything below.

| Check | Result |
|---|---|
| `php -l`, all 24 PHP files | **Pass** on PHP 7.4, 8.0, 8.1, 8.2, 8.3 |
| Unit suite (6 files) | **Pass** — 131 tests, 267 assertions, on all five PHP versions |
| Integration suite (5 files, single site) | **Pass** — 45 tests, 153 assertions, 1 skipped |
| Integration suite (multisite, `WP_MULTISITE=1`) | **Pass** — 45 tests, 153 assertions, 1 skipped |
| PHPCS (WordPress + PHPCompatibilityWP, `testVersion 7.4-`) | **0 errors**, 50 warnings (all expected — see below) |
| `node --check assets/admin.js` | **Pass** |
| `bash -n bin/*.sh` | **Pass** |

Environment: WordPress 7.1.4 test library, MariaDB 10.11, PHPUnit 9.6.39.

The one skipped test is `HeadersTest::test_a_foreign_policy_is_detected_and_recorded`.
`header()` and `headers_list()` are inert under the CLI SAPI, so a real header collision
cannot be staged there. It is skipped with that reason stated rather than rewritten into
something that cannot fail — a test that always passes is worse than an acknowledged gap.
That path needs the manual check in the deployment checklist below.

The 50 PHPCS warnings: 46 are `WordPress.DB.DirectDatabaseQuery.DirectQuery` /
`.NoCaching`, unavoidable for a plugin that owns a table; 3 are
`NoReservedKeywordParameterNames` on pre-existing signatures (`$list` in `covers()`,
`$string` in `parse_attrs()`) kept for symmetry with the code around them; 1 is
`Squiz.PHP.CommentedOutCode` on a pre-existing comment that gives an example of the
pattern its regex matches.

## What the tests caught in my own remediation

Worth recording, because it is the argument for running them rather than reasoning about
them. Eight defects were found after the code "looked right", six of them mine:

1. **`PolicySourceTest`: `*` survived a mangled paste.** `'self' https://evil.com;object-src *`
   split on whitespace leaves `*` as a valid token, so the row saved as "allow everything"
   while the admin's attention was on the rejected neighbour. `sanitize_sources()` now
   refuses network-wide sources when any sibling token was rejected.
2. **`PolicyHeaderTest`: a CRLF report URL was mangled, not refused.** `esc_url_raw()`
   strips control characters, so validating only its output accepted
   `https://host/csp\r\nX: 1` as `https://host/cspX:1`. `sanitize_report_url()` now checks
   the raw value first.
3. **`PolicySourceTest`: a trailing null byte was silently trimmed away.** `trim()`'s default
   character list includes `\0`, so the control-character check never saw it.
   `sanitize_source()` now looks before trimming.
4. **`CrawlerRedirectTest`: `mailto:` was resolved as a relative path**, becoming a
   same-origin URL with a nonsense path, which was then fetched. `resolve_hop()` now refuses
   any absolute URL whose scheme is not http(s).
5. **`ScannerExtractionTest`: a PHP fragment in a `<script>` tag was read as inline script**,
   so a scan of a theme template would propose re-adding `'unsafe-inline'` to a policy the
   owner had deliberately tightened.
6. **A literal `?>` inside a `//` comment ended PHP mode** and broke the file — caught
   immediately by `php -l`, which is exactly why the structural check I ran before Docker was
   not good enough.
7. **The unit bootstrap chose its mode from the machine, not the run.** It looked for an
   installed WordPress test library, so the unit suite silently changed behaviour and failed
   on any machine that also had the integration environment set up. Split into
   `tests/bootstrap-unit.php` and `tests/bootstrap-integration.php` with separate configs.
8. **`add_option()` was wrong for the rate-limit claim** (found by reading core, before the
   tests ran) — see §3 above.

Three further problems were in the tests themselves, not the plugin, and are worth knowing
for anyone extending them: `check_admin_referer()` reads `$_REQUEST` and not `$_POST`; a
handler ending in `exit` kills the PHPUnit process, so redirects must be interrupted at the
`wp_redirect` filter and AJAX handlers need `WP_Ajax_UnitTestCase`; and the WP test suite
rewrites `CREATE`/`DROP TABLE` into temporary-table statements, which silently neutered the
uninstall test's `DROP`.

# Remaining known risks

1. **`VALUES()` in `ON DUPLICATE KEY UPDATE`** is deprecated from MySQL 8.0.20 (a server
   warning, not an error). The row-alias alternative is unsupported on MySQL 5.7 and older
   MariaDB, so the compatible form was kept deliberately.
2. **Narrowing a wildcard can break a resource the scan never saw.** Inherent to CSP, not to
   this implementation; the panel says so and the plan is shown before it is applied.
3. **A foreign CSP added by the web server after PHP** cannot be seen by `headers_list()`;
   only the crawl detects it.
4. **Rate-limit counters live in `wp_options`** without a persistent object cache. The
   opportunistic sweep bounds this, but a very busy site should install an object cache or
   raise/disable the limit via `acsp_rate_limit`.
5. **`unsafe-inline` remains in the baseline**, as discussed.
6. **The `'allow'` path still trusts the operator**, by design: allowing a report writes an
   attacker-chosen origin into the policy if the operator clicks it. The risky-source
   warnings and the review-first default are the mitigation; no code can make this decision.

# WordPress compatibility

- PHP 7.4+ (no 8.x-only syntax; no typed properties, no arrow functions, no nullsafe).
- WordPress 5.8+ (`wp_using_ext_object_cache()`, `wp_cache_incr()`, `wp_rand()`,
  `register_rest_route()`, `WP_List_Table` all predate it).
- `autoload` written as `'no'`: WP 6.6+ normalises its own values to `'on'`/`'off'` but still
  treats anything outside the autoload set as not autoloaded, so this is safe on both.
- Multisite: unchanged and still per-site; `uninstall.php` still loops `get_sites()`.
- No new dependencies in the shipped plugin. Composer is dev-only — nothing in `includes/`
  requires an autoloader.

# Production deployment checklist

1. Install PHP + Composer; run `composer test` and `composer lint`; fix anything they find.
2. Stand up the WP test library and run the integration suite, including the multisite run
   (`WP_MULTISITE=1`).
3. Smoke-test on a staging copy of a real site: activate, confirm the policy is the baseline
   and nothing third-party was auto-added, run a full scan, approve findings, switch to
   enforce.
4. Exercise the block conflict explicitly: add `https://*.example.com` to `script-src`, get a
   report for a subdomain, block it, and check both paths (narrow, and keep-as-note).
5. Confirm the report endpoint: valid report stored, foreign `document-uri` refused,
   oversized body 413, limit 429 and recovery in the next minute.
6. Verify TLS: with a valid certificate the scan runs clean; with a broken one it warns.
7. Check the Overview screen on a host that sends its own CSP.
8. Run with a page cache enabled and confirm the header survives a purge.
9. Uninstall on a staging site and confirm the table, all five options and the counters are
   gone.

# Final release recommendation

**Ready for release review**, with the caveats below.

The High-severity finding (I-1) and all Medium findings are fixed in code, with the two
product decisions the maintainer made applied as specified, and no schema change to migrate.
The architecture, the resource caps and the existing good practice in the plugin were
preserved; the diff is additive and focused.

Validation has now run: syntax on five PHP versions, 131 unit tests, 45 integration tests
single-site and again under multisite, and PHPCS clean of errors. The suites found eight real
defects after the code already looked correct, which is the point of having them.

What is still outstanding before shipping is the part no test covers: the manual checks in
the deployment checklist, on a staging copy of a real site. Specifically the header-collision
path (skipped under CLI), behaviour behind a page cache, and the block-conflict flow seen
through the actual UI. None of those is a reason to delay the code review — they are the
last mile of a release, and they need a browser rather than a test runner.

---

# Phase 6 — Admin interface redesign (1.2.0)

Reviewed: 1.1.0 · Released as: 1.2.0 · Date: 2026-11-19

A presentation-layer release. No change to the policy engine, the scanner, the report
endpoint, the header writer or the database. The work was a redesign of the four admin
screens plus one new screen, carried out against the approved brand kit.

## 1. Problems in the 1.1.0 interface

1. No brand presence. `assets/admin.css` opened with "Uses wp-admin's own palette
   throughout" and the sidebar icon was core's `dashicons-shield`. The brand kit already
   in the repository was unused.
2. Overview was two screens in one: status cards, a help panel and the entire settings
   form (eight fields over two `form-table`s). There was no Settings screen.
3. Status was signalled by background colour alone — `.acsp-mode-*` and `.acsp-status-*`
   were colour-only pills. For a security product, mode is the single most important fact
   on the screen, and it failed WCAG 2.2 SC 1.4.1.
4. Up to six notices of equal weight could stack above the fold on Reports.
5. Fourteen identical directive rows asked the reader to already know which ones matter.
6. The scan log was an unstyled list with no progress indication and no live region, so a
   screen-reader user got nothing at all during a scan.
7. No media queries anywhere in 176 lines of CSS.
8. Destructive actions used `window.confirm()`.

## 2. What was changed

| Area | Change |
| --- | --- |
| Design system | `assets/css/tokens.css`, `components.css`, `screens.css` — tokens, components, layout, in that dependency order |
| Sidebar icon | `assets/css/menu-icon.css` — the approved mark as a CSS mask taking `currentColor` |
| Brand assets | `assets/brand/` — four SVGs, used by the masthead, the empty states and the menu |
| Dashboard | New screen at `acsp`: status panel, four figures from real data, quick actions |
| Settings | New screen at `acsp-settings`; the form moved unchanged |
| Policy | Directives grouped by blast radius |
| Scanner | Progress bar from the existing step sequence, live-region log, scan summary |
| Reports | Restyled list table, shaped badges, per-filter empty states |
| `assets/admin.css` | Deleted — fully superseded, no duplicated rules left behind |

Colour rule applied throughout: `#D6A72C` measures about 2.1:1 on white and is therefore
used for borders, fills and accents only, never for text. `#A97912` (about 4.6:1) is the
only gold permitted for small text, and primary buttons pair gold with charcoal rather
than white.

## 3. Security review of the redesign

No security control was changed, removed or relaxed.

* Capability checks (`ACSP_Admin::guard()`, `self::CAP`) — unchanged.
* `check_admin_referer()` on all six `admin_post_*` handlers, `check_ajax_referer()` on
  the scan stepper — unchanged.
* Every `admin_post_*` action name, the `wp_ajax_acsp_scan_step` step/cursor/auto
  protocol, the `ACSP` localised object and every form field name — unchanged, so no
  AJAX or REST contract moved.
* `apply_scan()` still matches posted values against the stored scan result, so a forged
  form still cannot inject a source. Covered by
  `AdminActionsTest::test_apply_scan_only_accepts_what_the_scan_offered`.
* New output: every variable reaching the `alert()` helper is escaped at the call site,
  `badge()` escapes internally, and all four brand URLs pass through `esc_url()`. PHPCS
  reports no `EscapeOutput` errors.
* One hook was added — `admin_body_class` — which appends a class and nothing else.

The only behavioural change is that `save_settings()` redirects to `acsp-settings`
instead of `acsp`, and the Plugins-screen action link follows it.

## 4. Scoping

The three screen stylesheets are scoped under `.acsp-wrap` and load only on the plugin's
own screens. `menu-icon.css` is the one stylesheet loaded on every admin page, because
the sidebar is on every admin page; it is 55 lines and every selector is scoped to
`#toplevel_page_acsp`. Core's own controls are restyled only inside `.acsp-wrap`.

## 5. Validation (executed)

Docker, `php:8.3-cli`, MariaDB 10.11, WordPress 7.1.5.

| Check | Result |
| --- | --- |
| `php -l`, 11 files | Pass |
| PHPCS, WordPress standard, errors | Pass — 0 errors |
| Unit suite | Pass — 131 tests, 267 assertions |
| Integration suite | Pass — 45 tests, 153 assertions, 1 skipped |
| Integration suite, multisite | Pass — 45 tests, 153 assertions, 1 skipped |
| `node --check assets/admin.js` | Pass |
| Browser console, all five screens | No errors |

Exercised in a live WordPress: a full AJAX scan end to end, a row Allow with its status
change, the confirmation dialog through to a bulk delete, keyboard focus, and both 1440px
and 390px layouts with no horizontal overflow.

## 6. Defects found by looking at the rendered screens

Three defects that passed both linting and the test suite were only visible once the
pages were rendered in a browser:

1. The status badge was clipped by the list table's `overflow: hidden`.
2. "The public website" was a disabled checkbox. A browser draws one as an empty box, so
   it read as "off" directly beside the words "always on".
3. The confirmation dialog was appended to `<body>`, outside the element the design
   tokens are declared on, so every colour resolved to nothing and it rendered as
   transparent text over the page.

All three are fixed. The lesson is the same one Phase 5 recorded for the engine: the
suites verify behaviour, not what the user actually sees.

## 7. Release recommendation for 1.2.0

**Ready for release review.** No functionality was removed, no contract moved and no
security control was weakened. `ACSP_VERSION` is `1.2.0`, so all four stylesheets and the
script cache-bust together. `ACSP_DB::DB_VERSION` stays `'1'`: there is nothing to
migrate.

Outstanding before shipping is packaging rather than code. The plugin directory still
contains development files — `tests/`, `bin/`, `AUDIT.md`, `composer.json`, the PHPUnit
and PHPCS configuration, the Docker files and the 372 KB brand kit — which belong in the
repository but not in a distributed zip. A `.distignore` or a build step should exclude
them. This is pre-existing and not introduced by the redesign.

---

# Phase 7 — Release packaging (1.2.0)

Date: 2026-11-19. No code behaviour was changed in this phase; it covers the project
layout, the distribution package and two manual checks that earlier phases had left open.

## 1. Final project layout

```
auto-csp-manager/
├── auto-csp-manager.php          bootstrap, constants, 7 require_once
├── readme.txt                    user-facing
├── uninstall.php                 per-site teardown, multisite aware
├── includes/                     9 runtime classes, unchanged
├── assets/
│   ├── css/  tokens, components, screens, menu-icon
│   ├── js/   admin.js            moved here from assets/ in this phase
│   └── brand/                    the 4 SVGs the plugin actually references
├── languages/                    textdomain target, shipped empty
│
├── tests/  bin/  AUDIT.md  RELEASE-CHECKLIST.md
├── composer.json  phpunit*.xml.dist  phpcs.xml.dist
├── Dockerfile.test  docker-compose.test.yml   PHPUnit and PHPCS
├── docker-compose.wp.yml                      browser verification
├── .gitignore  .distignore  build/
└── auto-csp-manager-refined-brand-kit/        approved source artwork
```

`assets/admin.js` moved to `assets/js/admin.js`. It had exactly one reference, in
`ACSP_Admin::assets()`, which was updated with it; the enqueue handle `acsp-admin` is
unchanged, so nothing that depends on it breaks.

## 2. Provenance of two files the previous review flagged

* **`docker-compose.wp.yml`** was never in the repository. It was written ad hoc in a
  temporary directory to render the screens in a browser during the 1.2.0 redesign, which
  is how three defects invisible to the test suite were caught. Rather than discard it, it
  is now a documented part of the development environment, separate from
  `docker-compose.test.yml`, which remains what `bin/ci.sh` runs.
* **`assets/css/components.css`** is new in 1.2.0 and is the component layer of the design
  system. Verified this phase: 1,463 lines, braces and comment blocks balanced, 238 rules,
  and no unscoped top-level selector — everything sits under `.acsp-wrap`, `body.acsp-*`
  or `#adminmenu`. Its two most recent edits were the fix for a settings screen that
  scrolled sideways on a phone: `max-width: 100%` on inputs, because core's
  `.regular-text` is 25em, and `min-width: 0` on the choice fieldset, because a fieldset
  defaults to `min-inline-size: min-content`.

## 3. Distribution

`bin/build-release.sh` builds `build/auto-csp-manager-<version>.zip` from an explicit
allowlist. A denylist fails open — a development file nobody remembered to exclude ships
to every user — whereas an allowlist fails closed, and the asset and include checks turn a
forgotten runtime file into a build failure.

The build aborts unless the plugin header, `ACSP_VERSION` and the readme stable tag agree,
a changelog entry exists, every `require_once` resolves inside the package, every
referenced asset is present (including the ones `brand_url()` composes at runtime), no
development file is present, and every staged PHP file parses. Existing artefacts are
never overwritten without `--force`.

## 4. The full-page cache check, finally run

Earlier phases listed this as outstanding. It has now been run, with WP Super Cache 1.12
in its default PHP caching mode:

* cache miss — the response carried `Content-Security-Policy-Report-Only`;
* cache hit — the response carried **no CSP header at all**.

WP Super Cache replays a stored snapshot of the response headers, and that snapshot did
not contain the CSP header. Any cache that serves a page without running WordPress and
does not store and replay the header will leave cached visitors with no policy. This is
inherent to page caching rather than a defect in this plugin, and no code was changed for
it in a packaging release, but it is a real gap between what the Dashboard reports and
what a visitor receives. `readme.txt` now says so directly; the previous wording
("may serve a stale header until purged") understated it.

Worth considering for a future version: detect a known page cache and warn on the
Dashboard, exactly as a foreign CSP is already detected.

## 5. Header collision, also run

With a second `Content-Security-Policy` injected ahead of the plugin, both headers
appeared on the response, the collision was recorded in `acsp_foreign_csp`, and the
Dashboard showed the warning naming the offending header. The behaviour the integration
suite has to skip under CLI is confirmed working against a live site.

## 6. Version control

Still none. Three reviews have now recorded this. Nothing in this phase can be expressed
as a diff, and the 1.0.0 archive that was being used as an informal baseline disappeared
from the parent directory during the previous review. A `.gitignore` is in place and the
tree is clean; initialising the repository was deliberately left for the maintainer to
approve rather than done unasked.
