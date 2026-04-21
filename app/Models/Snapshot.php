<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One captured page within a CrawlRun. HTML lives on disk; this row is
 * the searchable metadata.
 *
 * @property int $id
 * @property int $crawl_run_id
 * @property string $url
 * @property string $path
 * @property int $status_code
 * @property string|null $title
 * @property string $html_path
 * @property int $asset_count
 * @property int $html_bytes
 */
class Snapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'crawl_run_id',
        'url',
        'path',
        'status_code',
        'title',
        'html_path',
        'asset_count',
        'html_bytes',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'asset_count' => 'integer',
            'html_bytes'  => 'integer',
        ];
    }

    public function crawlRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** Loads the stored HTML body from the archive disk. */
    public function readHtml(): string
    {
        return Storage::disk('archive')->get($this->html_path) ?? '';
    }
}
