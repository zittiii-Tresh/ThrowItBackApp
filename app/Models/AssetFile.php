<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One physical file in the dedup pool. Keyed by SHA-256 of its bytes.
 *
 * Lifecycle:
 *   1. AssetDownloader fetches a URL, computes sha256(bytes).
 *   2. Asks `firstOrCreatePool($sha256, $bytes, $mime)` — either reuses
 *      an existing pool entry (no disk write) or creates a new one
 *      (writes the bytes to the pool path).
 *   3. Each new Asset row that references this file calls `addRef()` to
 *      bump the count.
 *   4. When an Asset row is deleted, `releaseRef()` decrements; if the
 *      count hits 0 the physical file is deleted from disk.
 *
 * The pool path layout `_pool/{ab}/{cd}/{full-sha}.{ext}` spreads files
 * across 65,536 directories so no single dir gets unmanageably large.
 *
 * @property int    $id
 * @property string $sha256
 * @property string $storage_path
 * @property int    $size_bytes
 * @property ?string $mime_type
 * @property int    $ref_count
 */
class AssetFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'sha256', 'storage_path', 'size_bytes', 'mime_type', 'ref_count',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'ref_count'  => 'integer',
        ];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * Find an existing pool entry by sha256, or create one by writing
     * the bytes to the pool path. Idempotent — if two crawls race, the
     * unique index on sha256 ensures only one row wins; the loser
     * re-fetches the existing row.
     *
     * Returns the AssetFile (existing or newly created). Does NOT bump
     * the ref count — caller must call addRef() when an Asset row is
     * created that points at this file.
     */
    public static function firstOrCreatePool(string $sha256, string $bytes, ?string $mime, ?string $extension = null): self
    {
        $existing = static::where('sha256', $sha256)->first();
        if ($existing) {
            // Already in the pool — no disk write needed.
            return $existing;
        }

        $path = static::poolPathFor($sha256, $extension ?? static::extensionFromMime($mime));

        // Write the file ONCE. If parallel crawls race, second arrival
        // either rewrites the same bytes harmlessly or hits the unique-
        // index error below and re-reads the existing row.
        Storage::disk('archive')->put($path, $bytes);

        try {
            return static::create([
                'sha256'       => $sha256,
                'storage_path' => $path,
                'size_bytes'   => strlen($bytes),
                'mime_type'    => $mime,
                'ref_count'    => 0,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Race lost — another process beat us to it. Use their row.
            return static::where('sha256', $sha256)->firstOrFail();
        }
    }

    /**
     * Bump the reference count when a new Asset row is created that
     * points at this file. Atomic increment — safe under concurrency.
     */
    public function addRef(): void
    {
        $this->increment('ref_count');
    }

    /**
     * Decrement the reference count. If it reaches zero, the physical
     * file on disk is no longer needed by any snapshot — delete it.
     * Atomic decrement + check.
     */
    public function releaseRef(): void
    {
        $this->decrement('ref_count');
        $this->refresh();

        if ($this->ref_count <= 0) {
            // No more snapshots reference this file — free the disk space.
            if (Storage::disk('archive')->exists($this->storage_path)) {
                Storage::disk('archive')->delete($this->storage_path);
            }
            $this->delete();
        }
    }

    /**
     * Pool path layout: `_pool/{ab}/{cd}/{full-hash}.{ext}` where ab/cd
     * are the first 4 chars of the sha256 (giving 65,536 evenly-spread
     * subdirectories). Optional extension preserves a hint of the file
     * type for human inspection — purely cosmetic, not used for dispatch.
     */
    public static function poolPathFor(string $sha256, ?string $extension = null): string
    {
        $a = substr($sha256, 0, 2);
        $b = substr($sha256, 2, 2);
        $ext = $extension ? '.' . ltrim($extension, '.') : '';
        return "_pool/{$a}/{$b}/{$sha256}{$ext}";
    }

    /**
     * Best-effort mime → extension. Just for human-readable pool paths;
     * the lookup never depends on the extension being correct.
     */
    protected static function extensionFromMime(?string $mime): ?string
    {
        if (! $mime) return null;
        $mime = strtolower(trim(explode(';', $mime)[0]));
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png'               => 'png',
            'image/gif'               => 'gif',
            'image/webp'              => 'webp',
            'image/svg+xml'           => 'svg',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            'text/css'                => 'css',
            'text/html'               => 'html',
            'application/javascript', 'text/javascript' => 'js',
            'application/json'        => 'json',
            'font/woff2'              => 'woff2',
            'font/woff'               => 'woff',
            'font/ttf', 'application/x-font-ttf' => 'ttf',
            'font/otf', 'application/x-font-otf' => 'otf',
            'application/vnd.ms-fontobject' => 'eot',
            default => null,
        };
    }
}
