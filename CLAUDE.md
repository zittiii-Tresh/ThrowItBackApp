# SiteArchive — session handoff

Internal web-archive tool for Sites at Scale. Wayback-Machine-style captures
of managed sites on a schedule, with a Filament admin + Livewire user UI.

Started from a blank machine; this file captures where the previous session
left off so a fresh Claude pick-up has full context.

---

## Stack (decided up-front, do not change without reason)

| Layer | Choice | Notes |
|---|---|---|
| Framework | **Laravel 11.51** | not 12 or 13 — Filament 3 requires 10/11 |
| Admin UI | **Filament 3** | auto-installed Livewire 3 as a peer dep |
| User UI | **Livewire 3** full-page components | 4 components in `app/Livewire/` |
| CSS | **Tailwind 3** (not 4) | brand palette in `tailwind.config.js` |
| DB | **MySQL 8.4** (Laragon) | `site_archive` database |
| Cache / sessions | **Redis 5** | installed as a Windows service |
| Crawl engine | **spatie/crawler 8** + Guzzle | see CrawlSiteJob |
| Diff | **sebastian/diff** | Compare screen |
| OS | **Windows 10/11 + Laragon** | prod would be Linux — all code is cross-platform |

### Windows-specific gotchas (already handled, don't re-solve)

- **No `ext-pcntl`** — `composer require --ignore-platform-req=ext-pcntl` is
  how every install ran. Horizon is installed but not used on dev.
- **No phpredis extension** — using `predis/predis` (pure PHP Redis client).
- **SSL CA bundle** — `curl.cainfo` + `openssl.cafile` in `php.ini` point at
  `C:/laragon/etc/ssl/cacert.pem`. Without this, Guzzle fails all HTTPS.
- **Queue workers don't run reliably on Windows** (no pcntl). We deliberately
  avoid `queue:work` for scheduled crawls — see "Zero-maintenance" below.

---

## What's built — 20 commits on `main`

Phases roughly track the proposal PDF's 7 admin + 5 user screens.

| Phase | Commit | What |
|---|---|---|
| Scaffold | `cb4f6c8` | Laravel 11 + Filament 3 + Horizon install, admin user, `.env` for MySQL/Redis |
| Landing polish | `ba48a3d` | gradient + drifting purple blobs on `/` |
| 2 — Sites | `85c5eb9` | `sites` table, `Site` model, `SiteResource` Filament CRUD, frequency enums, `Schedule::nextRunFor` |
| 3 — Crawl engine | `bef603d` | `crawl_runs`/`snapshots`/`assets` tables, `CrawlSiteJob`, `ArchiveCrawlObserver`, `AssetDownloader`, `HtmlRewriter`, `SnapshotStorage` |
| 3.5 — Playback preview | `644d405` | `ArchiveController`, inline `style="background-image"` rewrite, CSS `url()` rewrite |
| 4 — Scheduler wiring | `166afd9` | `DispatchDueCrawlsCommand`, `routes/console.php` schedule |
| 5 — Admin screens | `4aba50f` | Dashboard widgets, CrawlRun resource, `system_events`/`settings` tables, Notifications + Settings pages |
| Cleanup | `54fefdc`, `7e98226` | removed demo/seed data — only real sites |
| Admins | `da8d0e7` + `d14de95` + `319bffa` | `/admin/users` CRUD, email verification flow, surfaced Delete |
| 6 — User archive | `43e8ec3`, `b315631`, `7bda748` | ArchiveHome, ArchiveBrowse (calendar), ArchiveViewer (iframe + toolbar + assets), ArchiveCompare |
| 6f — Crawl fixes | `ca26a60` | concurrency=3, delay 200ms, font mime fallback |
| 7 — Polish | `498a6b6` | live progress bar, consistent View actions, notification hover glow, dark mode contrast |
| Detached fix | `45d0ce8` | `popen('start /B ...')` so crawls actually run after Filament request returns |
| Zero-maintenance | `b6f1759` | `DispatchDueCrawlsCommand` spawns detached too; Windows Task Scheduler; Redis as service |
| Notifications scope | `c9febb4` | filter to `event='crawl.failed'`; drop `runInBackground()` from schedule (silences cmd flash) |

---

## Current runtime state

### Services (should all be up when dev machine is on)

```
MySQL    — via Laragon (user must tick "Auto start" in Laragon preferences)
Redis    — Windows service, StartType=Automatic, runs as SYSTEM
Laravel  — `php artisan serve` (the user starts this manually per dev session)
```

Check with:
```bash
source ~/.bash_profile
mysqladmin ping -h 127.0.0.1 -u root
redis-cli ping
curl -s -o /dev/null -w "HTTP %{http_code}\n" http://127.0.0.1:8000/
```

### Windows Scheduled Task — "SiteArchive Scheduler"

```
Execute:    C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php-win.exe
Arguments:  artisan schedule:run
Cwd:        C:\Users\sitesatscale\Documents\SAS SiteArchive\site-archive
Trigger:    every 1 minute, repeats 3650 days
Principal:  current user, Interactive logon, Limited privileges
```

Registered via `Register-ScheduledTask` in PowerShell (not in repo — it's a
Windows config). Re-register command is preserved in the commit message of
`b6f1759` if the task ever needs rebuilding.

### Real sites in DB

| id | name | URL | notes |
|---|---|---|---|
| 4 | Honeycomb Agency | https://www.honeycombagency.com.au | never crawled |
| 7 | TEST | https://tmdbportfolio.netlify.app | multiple successful runs, primary test target |
| 8 | TEST 2 | https://gpagalingportfolio.netlify.app | tiny SPA, useful for fast-feedback tests |

### Admin credentials

```
admin@sitesatscale.com / password
```

### Mail

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=unodostresh17@gmail.com
MAIL_PASSWORD=<in .env — gitignored>
MAIL_FROM_ADDRESS=sas@sitearchive.com    # Gmail "Send mail as" alias
```

---

## Architectural decisions worth remembering

### 1. "Crawl now" and scheduled crawls both use detached `popen` spawn

Rather than dispatch jobs to the Redis queue and require a `queue:work` worker
to process them (which is fragile on Windows), both flows spawn
`php artisan crawl:run {id} --run-id={runId}` as a detached process:

- Parent (Filament request OR scheduler tick) creates a `CrawlRun` row
  in status=Running synchronously, so the UI sees "Crawling" on its next
  2-second poll.
- `popen('start /B "" php-win artisan crawl:run ...')` detaches the child
  on Windows. `start /B` is the key — Symfony Process's `->start()` does
  NOT detach cleanly on Windows.
- Child process survives the parent's exit and updates the existing
  `CrawlRun` row as pages complete.

### 2. Html rewriting happens at crawl time for assets, view time for anchors

- **Asset refs** (`<img>`, `<link>`, `<script>`, inline `style` bg-images, CSS
  `url()`) are rewritten at crawl time to `/archive/asset/{snapshot_id}/{sha1}`
  — because we need them to point at archived copies, not the live (possibly
  deleted) originals.
- **`<a href>` anchor links** are rewritten at VIEW time in
  `ArchiveController::rewriteAnchors()` — because at crawl time the sibling
  snapshots may not exist yet (we're still crawling them).
- Outbound anchor links are neutralized to `href="#"` with the original URL
  kept in `data-archived-href="..."` — prevents accidentally leaving the archive.

### 3. Failed captures still get a snapshot row

When a page fetch returns 0/4xx/5xx, `ArchiveCrawlObserver::crawlFailed`
still creates a snapshot row (with empty `html_path`) so the Crawl History
can show what was attempted. The viewer renders a "Capture failed" empty
state for these instead of 404-ing — keeps the "every row has View" UX
the user asked for.

### 4. Only crawl failures show in Notifications

`Notifications::failureQuery()` scopes to `event='crawl.failed'`. Successful
crawls, partial crawls, storage warnings, "site added" events are all still
logged to `system_events` (potential future activity log) but kept out of
the feed. User-requested.

### 5. php-win.exe over php.exe for scheduled runs

Windows Task Scheduler fires `php-win.exe` (the windowless PHP subsystem),
not `php.exe`, so no console window flashes. `PHP_BINARY` inside the
running process is `php-win.exe`, so detached crawl spawns also inherit it
and are silent.

---

## Known issues / deferred

- **GitHub push**: `sitesatscale/site-archive` returned "Repository not found"
  at the start. Still not pushed — user needs to confirm repo exists or
  create it empty. All 20 commits are local on `main`.
- **SPA pages intermittently status=0**: Netlify cold starts + rate limits
  sometimes make crawls return zero status. Already mitigated with
  concurrency=3, 200ms delay, 30s timeout. True fix would be Browsershot
  (headless Chrome) — deferred to when a site really needs it.
- **True 24/7 automation**: Windows Task Scheduler only fires when the PC is
  on. For run-even-when-PC-is-off, deploy to a Linux VPS — Laravel Forge +
  DigitalOcean ~$17/mo, or free tiers on Fly.io/Railway. Deferred.
- **Assets "other" bucket**: a handful of assets (JSON manifests, misc)
  still fall into `other`. AssetType classifier handles the common cases
  (images, CSS, JS, fonts) — extending it when new types show up is easy.

---

## Where to resume from

1. **User wants to push to GitHub** → ask for the correct repo URL, then
   `git remote add origin …` + `git push -u origin main`. Confirm before
   push per the original plan.
2. **User wants a new feature** → follow the existing phase pattern, commit
   per phase with detailed commit messages, don't introduce a new model
   without checking the existing schema.
3. **User reports a bug** → check `storage/logs/laravel.log` first, then
   tail the Windows Scheduled Task history if it's scheduler-related.

Commit messages follow a `type(scope): subject` prefix (see `git log`) with
a detailed body. Every commit includes `Co-Authored-By: Claude …`. Stick to
the pattern.
