<?php

namespace App\Models;

use App\Enums\CrawlStatus;
use App\Enums\TriggerSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One execution of CrawlSiteJob against a Site.
 *
 * @property int $id
 * @property int $site_id
 * @property CrawlStatus $status
 * @property TriggerSource $triggered_by
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int $pages_crawled
 * @property int $assets_downloaded
 * @property int $storage_bytes
 * @property string|null $error_message
 */
class CrawlRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'status',
        'triggered_by',
        'started_at',
        'finished_at',
        'pages_crawled',
        'assets_downloaded',
        'storage_bytes',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status'            => CrawlStatus::class,
            'triggered_by'      => TriggerSource::class,
            'started_at'        => 'datetime',
            'finished_at'       => 'datetime',
            'pages_crawled'     => 'integer',
            'assets_downloaded' => 'integer',
            'storage_bytes'     => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }

    /** Every asset captured across all snapshots in this run. */
    public function assets(): HasManyThrough
    {
        return $this->hasManyThrough(Asset::class, Snapshot::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Duration of the run in seconds, or null if not finished. */
    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }
        return $this->started_at->diffInSeconds($this->finished_at);
    }

    /** "4m 12s" / "12s" / "—" formatted duration for the table columns. */
    public function durationHuman(): string
    {
        $seconds = $this->durationSeconds();
        if ($seconds === null) {
            return '—';
        }
        $minutes = intdiv($seconds, 60);
        $seconds = $seconds % 60;
        return $minutes > 0
            ? "{$minutes}m {$seconds}s"
            : "{$seconds}s";
    }
}
