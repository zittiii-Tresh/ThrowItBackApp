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
