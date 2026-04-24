<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One image / stylesheet / script / font captured for a Snapshot.
 *
 * @property int $id
 * @property int $snapshot_id
 * @property string $url
 * @property AssetType $type
 * @property string|null $mime_type
 * @property int $size_bytes
 * @property string $storage_path
 * @property int $status_code
 */
class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_id',
        'asset_file_id',     // FK into the dedup pool (asset_files table)
        'url',
        'type',
        'mime_type',
        'size_bytes',
        'storage_path',
        'status_code',
    ];

    protected function casts(): array
    {
        return [
            'type'        => AssetType::class,
            'size_bytes'  => 'integer',
            'status_code' => 'integer',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    /**
     * Pooled physical file. Multiple Asset rows (across snapshots/runs)
     * may point at the same AssetFile — that's how dedup saves space.
     * Nullable for legacy rows captured before the pool existed.
     */
    public function assetFile(): BelongsTo
    {
        return $this->belongsTo(AssetFile::class);
    }

    /**
     * Resolved storage path: the pool path if this asset is deduped,
     * otherwise the legacy per-run path. Lets the controller serve
     * pre- and post-migration assets uniformly.
     */
    public function effectiveStoragePath(): ?string
    {
        if ($this->asset_file_id && $this->relationLoaded('assetFile')) {
            return $this->assetFile?->storage_path;
        }
        if ($this->asset_file_id) {
            return $this->assetFile()->value('storage_path');
        }
        return $this->storage_path !== '' ? $this->storage_path : null;
    }

    /**
     * Decrement the pool ref-count when this Asset is deleted, so the
     * pool can garbage-collect orphan files. Falls back to deleting the
     * legacy per-run file directly for un-migrated rows.
     */
    protected static function booted(): void
    {
        static::deleting(function (Asset $asset) {
            if ($asset->asset_file_id) {
                $asset->assetFile?->releaseRef();
            } elseif ($asset->storage_path !== '') {
                // Legacy row not yet migrated — delete its dedicated file.
                if (Storage::disk('archive')->exists($asset->storage_path)) {
                    Storage::disk('archive')->delete($asset->storage_path);
                }
            }
        });
    }

    /** Original filename inferred from the URL path — shown in the asset panel. */
    public function basename(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH) ?: '';
        return basename($path) ?: '(unnamed)';
    }

    /** "128 KB" / "1.2 MB" / "38 B" formatted size. */
    public function sizeHuman(): string
    {
        $bytes = $this->size_bytes;
        if ($bytes < 1024)          return $bytes . ' B';
        if ($bytes < 1024 * 1024)   return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1024 ** 3)     return round($bytes / 1024 ** 2, 1) . ' MB';
        return round($bytes / 1024 ** 3, 1) . ' GB';
    }

    /** Where the archived file lives, for serving to the viewer iframe. */
    public function readBinary(): string
    {
        return Storage::disk('archive')->get($this->storage_path) ?? '';
    }
}
