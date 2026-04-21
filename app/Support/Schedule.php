<?php

namespace App\Support;

use App\Enums\FrequencyType;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Resolves a Site's next_run_at timestamp from its frequency configuration.
 *
 * Used by:
 *   - the Phase 4 Laravel Scheduler task that ticks once per minute to dispatch
 *     CrawlSiteJob for any site where next_run_at <= now()
 *   - Admin Screen 5 (Schedules) — displayed as "Next: Today 20:00"
 *   - Admin Screen 1 (Dashboard) — "Upcoming Today" card
 */
class Schedule
{
    /**
     * Compute the next run moment for a site, relative to `from` (defaults to
     * "now"). Returns null when the configuration is incomplete — e.g. a
     * specific-days schedule with no days selected or a malformed cron.
     */
    public static function nextRunFor(Site $site, ?CarbonImmutable $from = null): ?Carbon
    {
        $from = $from ?? CarbonImmutable::now();

        return match ($site->frequency_type) {
            FrequencyType::Daily        => self::nextDaily($from),
            FrequencyType::EveryNDays   => self::nextEveryNDays($site, $from),
            FrequencyType::SpecificDays => self::nextSpecificDays($site, $from),
        };
    }

    /** Daily → next midnight boundary (crawls overnight by convention). */
    protected static function nextDaily(CarbonImmutable $from): Carbon
    {
        return Carbon::parse($from->addDay()->startOfDay());
    }

    /**
     * Every N days → `last_crawled_at + N days`, or N days from now if the
     * site has never been crawled. Clamped to a minimum of 1 day.
     */
    protected static function nextEveryNDays(Site $site, CarbonImmutable $from): Carbon
    {
        $days = max(1, (int) ($site->frequency_config['days'] ?? 2));

        $base = $site->last_crawled_at
            ? CarbonImmutable::parse($site->last_crawled_at)
            : $from;

        return Carbon::parse($base->addDays($days)->startOfDay());
    }

    /**
     * Specific days of week → scan up to 8 days and pick the first match,
     * applying the configured time-of-day ("HH:MM" in frequency_config.time).
     *
     * If today is one of the target days and the time hasn't passed yet, we
     * pick today at that time. Otherwise start from tomorrow.
     */
    protected static function nextSpecificDays(Site $site, CarbonImmutable $from): ?Carbon
    {
        $days = array_map(
            'strtolower',
            (array) ($site->frequency_config['days'] ?? [])
        );
        if ($days === []) {
            return null;
        }

        $map = [
            'mon' => 1, 'tue' => 2, 'wed' => 3,
            'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0,
        ];
        $targetDows = collect($days)->map(fn ($d) => $map[$d] ?? null)->filter()->values();
        if ($targetDows->isEmpty()) {
            return null;
        }

        // Parse "HH:MM" (default midnight).
        [$hour, $minute] = explode(':', (string) ($site->frequency_config['time'] ?? '00:00')) + [0, 0];
        $hour   = max(0, min(23, (int) $hour));
        $minute = max(0, min(59, (int) $minute));

        // Candidate for "today at the configured time" — only valid if it
        // matches a target day AND hasn't already passed.
        $todayAtTime = $from->setTime($hour, $minute, 0);
        if ($todayAtTime->greaterThan($from) && $targetDows->contains($todayAtTime->dayOfWeek)) {
            return Carbon::parse($todayAtTime);
        }

        // Otherwise walk forward day by day. Max 8 iterations — one of the
        // 7 days of the week is guaranteed to match.
        $cursor = $from->addDay()->setTime($hour, $minute, 0);
        for ($i = 0; $i < 8; $i++) {
            if ($targetDows->contains($cursor->dayOfWeek)) {
                return Carbon::parse($cursor);
            }
            $cursor = $cursor->addDay();
        }

        return null;
    }
}
