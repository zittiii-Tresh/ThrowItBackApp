<?php

namespace App\Models;

use App\Enums\FrequencyType;
use App\Support\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A registered website that SiteArchive crawls on a schedule.
 *
 * @property int $id
 * @property string $name
 * @property string $base_url
 * @property int $crawl_depth
 * @property int $max_pages
 * @property FrequencyType $frequency_type
 * @property array|null $frequency_config
 * @property array|null $notify_channels
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_crawled_at
 * @property \Illuminate\Support\Carbon|null $next_run_at
 */
class Site extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'base_url',
        'crawl_depth',
        'max_pages',
        'frequency_type',
        'frequency_config',
        'notify_channels',
        'is_active',
        'last_crawled_at',
        'next_run_at',
        'retention_months',  // null = use global default; 0 = keep forever; 1-12 = months
    ];

    protected function casts(): array
    {
        return [
            'crawl_depth'      => 'integer',
            'max_pages'        => 'integer',
            'frequency_type'   => FrequencyType::class,
            'frequency_config' => 'array',
            'notify_channels'  => 'array',
            'is_active'        => 'boolean',
            'last_crawled_at'  => 'datetime',
            'next_run_at'      => 'datetime',
            'retention_months' => 'integer',
        ];
    }

    /**
     * Resolve the effective retention for this site:
     *   - null     → falls back to Setting::current()->default_retention_months
     *   - 0        → keep forever (returns null to mean "no cutoff")
     *   - 1..12    → that many months
     *
     * Returns the cutoff Carbon date — anything older should be deleted —
     * or null if the site keeps history forever.
     */
    public function retentionCutoff(): ?\Illuminate\Support\Carbon
    {
        $months = $this->retention_months;
        if ($months === null) {
            $months = (int) (Setting::current()->default_retention_months ?? 3);
        }
        if ($months <= 0) {
            return null; // keep forever
        }
        return now()->subMonths($months);
    }

    /** Human label for the dashboard / site form. */
    public function retentionLabel(): string
    {
        if ($this->retention_months === null) {
            $default = (int) (Setting::current()->default_retention_months ?? 3);
            return "Use default ({$default} months)";
        }
        if ($this->retention_months === 0) {
            return 'Keep forever';
        }
        return "Keep {$this->retention_months} month" . ($this->retention_months === 1 ? '' : 's');
    }

    /**
     * Auto-recompute next_run_at whenever a site's schedule-related fields
     * change. Keeps the scheduler's "due now" query cheap — no need to evaluate
     * cron expressions on every tick, just read next_run_at.
     */
    protected static function booted(): void
    {
        static::saving(function (Site $site): void {
            if (! $site->isDirty(['frequency_type', 'frequency_config', 'is_active'])) {
                return;
            }

            $site->next_run_at = $site->is_active
                ? Schedule::nextRunFor($site, CarbonImmutable::now())
                : null;
        });
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function crawlRuns(): HasMany
    {
        return $this->hasMany(CrawlRun::class);
    }

    /**
     * Newest crawl run — used by the Dashboard "Recent crawl runs" card and
     * the All sites table's "Last crawl" status.
     */
    public function latestCrawlRun(): HasOne
    {
        return $this->hasOne(CrawlRun::class)->latestOfMany();
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /** Active (not paused) sites only. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Sites whose next_run_at has passed — candidates for the Phase 4
     * scheduler to dispatch. Pausing a site sets next_run_at to null so
     * it won't be picked up.
     */
    public function scopeDueNow(Builder $q): Builder
    {
        return $q->active()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Human-readable description of this site's crawl frequency — used in
     * the All sites table (Admin Screen 2) and the Schedules cards (Screen 5).
     */
    public function describeFrequency(): string
    {
        return match ($this->frequency_type) {
            FrequencyType::Daily        => 'Daily',
            FrequencyType::EveryNDays   => sprintf(
                'Every %d day%s',
                $days = (int) ($this->frequency_config['days'] ?? 2),
                $days === 1 ? '' : 's',
            ),
            FrequencyType::SpecificDays => $this->describeSpecificDays(),
        };
    }

    /**
     * "MWF at 20:00" / "Tue/Thu" style compact label for specific-day schedules.
     * Days are 3-letter lowercase ("mon", "tue", ...) in frequency_config.
     * Time is an optional "HH:MM" string in frequency_config.time.
     */
    protected function describeSpecificDays(): string
    {
        $days = (array) ($this->frequency_config['days'] ?? []);
        if ($days === []) {
            return 'Specific days — none selected';
        }

        // MWF shorthand for the exact set {mon,wed,fri} — matches the
        // schedule card examples in the proposal PDF.
        $set = array_map('strtolower', $days);
        sort($set);

        $dayLabel = match ($set) {
            ['fri', 'mon', 'wed'] => 'MWF',
            ['thu', 'tue']        => 'Tue/Thu',
            default               => collect($days)
                ->map(fn (string $d) => ucfirst(substr($d, 0, 3)))
                ->join(', '),
        };

        // Append "at HH:MM" if a time is set AND it's not midnight (midnight
        // is the default and doesn't need to be called out).
        $time = $this->frequency_config['time'] ?? null;
        if ($time && $time !== '00:00') {
            return "$dayLabel at $time";
        }

        return $dayLabel;
    }
}
