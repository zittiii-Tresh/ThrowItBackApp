# SiteArchive

Internal Wayback-Machine-style web archive for SAS-managed client sites. Captures full snapshots (HTML + assets) on a schedule and lets admins browse / recover any version.

Built for **Sites at Scale** to answer questions like:

- *"Can you grab the homepage from Tuesday morning before the deploy broke things?"*
- *"What did the custom CSS look like before someone changed it?"*
- *"Recover the deleted hero image so we can re-upload it."*

## Stack at a glance

| Layer | Choice |
|---|---|
| Framework | Laravel 11 |
| Admin UI | Filament 3 |
| User-facing UI | Livewire 3 + Tailwind 3 |
| DB | MySQL 8 |
| Cache / sessions | Redis 5 |
| Crawler | spatie/crawler 8 + Guzzle (URL discovery) |
| Page rendering | **Browsershot** (real Chromium) for accuracy |
| Storage | Content-addressed dedup pool (one physical file per unique sha256) |
| Hosting | Single-machine local install (Windows + Laragon today) |

## Key features

- **Per-site crawl scheduling** — daily, every-N-days, specific weekdays + time-of-day
- **Browsershot rendering** — captures the page exactly as users see it (JS, lazy images, web fonts)
- **Content-addressed dedup pool** — identical files (logos, theme CSS, etc.) stored once on disk regardless of how many crawls reference them
- **Tiered retention** — per-site auto-deletion (1/2/3/6/12 months or forever), nightly cleanup
- **Trash with 7-day recovery window** — soft-deleted crawls stay restorable for a week before disk space frees
- **Diff/Compare** — side-by-side HTML diff between any two snapshots
- **Calendar browsing** — pick a day, see what was captured

## Documentation

| Doc | What it's for |
|---|---|
| **[`SETUP.md`](./SETUP.md)** | "How do I install this on a new machine?" — step-by-step install + config |
| **[`CLAUDE.md`](./CLAUDE.md)** | "How does this work?" — architecture, design decisions, current runtime state, where to pick up |

## Quick start

```bash
git clone https://github.com/zittiii-Tresh/ThrowItBack.git
cd ThrowItBack
# Then follow SETUP.md
```

## License

Internal SAS tool — not for redistribution.
