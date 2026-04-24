<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\AssetFile;
use App\Models\CrawlRun;
use App\Models\Snapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Hard-deletes crawl runs that have been in the trash longer than the
 * trash retention window (default: 7 days).
 *
 * Why this is its own command (not folded into RetentionRunCommand):
 *   - Retention runs every night and is fast — just sets deleted_at
 *   - Purge runs every night but only acts on rows past the trash window
 *   - Splitting them means an admin can manually trigger a purge early
 *     ("free that disk space NOW") without affecting fresh deletions
 *
 * Disk-freeing strategy (the heart of this command):
 *   1. For each candidate run: find every Asset row attached to its snapshots.
 *   2. Group those by asset_file_id (the dedup pool entry).
 *   3. For each pool entry, decrement ref_count by however many of THIS
 *      run's assets reference it (batch update, not row-by-row).
 *   4. If a pool entry's ref_count hits zero → its file is deleted from
 *      disk and the asset_files row is dropped.
 *   5. Hard-delete the CrawlRun (DB cascade tidies the snapshots + assets).
 *
 * This batched approach is way faster than firing Eloquent `deleting`
 * events on each of potentially millions of asset rows.
 *
 * Usage:
 *   php artisan archive:trash-purge                  # actual run
 *   php artisan archive:trash-purge --dry-run        # preview
 *   php artisan archive:trash-purge --age=14         # custom window (default 7d)
 */
class TrashPurgeCommand extends Command
{
    protected $signature   = 'archive:trash-purge
                              {--dry-run : Show what would be purged without deleting}
                              {--age=7   : Trash retention in days (default 7)}';

    protected $description = 'Hard-delete trashed crawl runs older than the trash window and free disk space';

    public function handle(): int
    {
        $dry      = (bool) $this->option('dry-run');
        $ageDays  = max(0, (int) $this->option('age'));
        $cutoff   = now()->subDays($ageDays);

        $candidates = CrawlRun::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info("No trashed crawls older than {$ageDays} days. Nothing to purge.");
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d trashed crawl(s) older than %d days. %s.',
            $candidates->count(),
            $ageDays,
            $dry ? 'Dry-run — no changes' : 'Purging...',
        ));

        $totalBytesFreed = 0;
        $totalPoolFilesDeleted = 0;

        foreach ($candidates as $run) {
            [$bytesFreed, $poolFilesDeleted] = $this->purgeRun($run, $dry);
            $totalBytesFreed += $bytesFreed;
            $totalPoolFilesDeleted += $poolFilesDeleted;

            $this->line(sprintf(
                '  run #%d (site %d, deleted %s) → freed %s, deleted %d pool files',
                $run->id,
                $run->site_id,
                $run->deleted_at->diffForHumans(),
                $this->humanBytes($bytesFreed),
                $poolFilesDeleted,
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d run(s), freed %s of disk space, deleted %d unique pool files.',
            $dry ? 'Would purge' : 'Purged',
            $candidates->count(),
            $this->humanBytes($totalBytesFreed),
            $totalPoolFilesDeleted,
        ));

        return self::SUCCESS;
    }

    /**
     * Purge one run. Returns [bytesFreed, poolFilesDeleted].
     */
    protected function purgeRun(CrawlRun $run, bool $dry): array
    {
        // Collect every asset_file_id used by this run's snapshots, with
        // a count of how many references this run holds for each.
        $snapshotIds = Snapshot::where('crawl_run_id', $run->id)->pluck('id');

        $refCounts = Asset::whereIn('snapshot_id', $snapshotIds)
            ->whereNotNull('asset_file_id')
            ->select('asset_file_id', DB::raw('COUNT(*) as n'))
            ->groupBy('asset_file_id')
            ->pluck('n', 'asset_file_id');

        $bytesFreed = 0;
        $poolFilesDeleted = 0;
        $disk = Storage::disk('archive');

        foreach ($refCounts as $assetFileId => $refsHere) {
            $assetFile = AssetFile::find($assetFileId);
            if (! $assetFile) continue;

            $newRefCount = $assetFile->ref_count - (int) $refsHere;
            if ($newRefCount <= 0) {
                // Last reference — physical file goes too.
                $bytesFreed += $assetFile->size_bytes;
                $poolFilesDeleted++;

                if (! $dry) {
                    if ($disk->exists($assetFile->storage_path)) {
                        $disk->delete($assetFile->storage_path);
                    }
                    $assetFile->delete();
                }
            } else {
                if (! $dry) {
                    $assetFile->update(['ref_count' => $newRefCount]);
                }
            }
        }

        // Also free legacy per-crawl files for any un-migrated rows in this run.
        $legacyAssets = Asset::whereIn('snapshot_id', $snapshotIds)
            ->whereNull('asset_file_id')
            ->where('storage_path', '!=', '')
            ->get(['id', 'storage_path', 'size_bytes']);

        foreach ($legacyAssets as $asset) {
            if ($disk->exists($asset->storage_path)) {
                $bytesFreed += $disk->size($asset->storage_path);
                if (! $dry) {
                    $disk->delete($asset->storage_path);
                }
            }
        }

        // Free the snapshot HTML files (these are NOT in the pool — one per snapshot).
        $htmlFiles = Snapshot::where('crawl_run_id', $run->id)
            ->where('html_path', '!=', '')
            ->pluck('html_path');

        foreach ($htmlFiles as $path) {
            if ($disk->exists($path)) {
                $bytesFreed += $disk->size($path);
                if (! $dry) {
                    $disk->delete($path);
                }
            }
        }

        if (! $dry) {
            // Hard-delete the run. DB cascade handles snapshots + assets rows.
            $run->forceDelete();
        }

        return [$bytesFreed, $poolFilesDeleted];
    }

    protected function humanBytes(int $b): string
    {
        if ($b < 1024) return $b . ' B';
        if ($b < 1024 ** 2) return round($b / 1024, 1) . ' KB';
        if ($b < 1024 ** 3) return round($b / 1024 ** 2, 1) . ' MB';
        return round($b / 1024 ** 3, 2) . ' GB';
    }
}
