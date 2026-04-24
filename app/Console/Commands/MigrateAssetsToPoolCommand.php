<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\AssetFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-shot migration: convert existing per-crawl asset files into the
 * deduped `asset_files` pool.
 *
 * Safe-by-default:
 *   - Dry-run mode (default): reports what WOULD happen, no writes.
 *   - --commit: actually writes pool entries + updates assets.asset_file_id
 *               (but leaves legacy per-crawl files on disk as a safety copy)
 *   - --cleanup: deletes the legacy per-crawl files AFTER --commit verified
 *
 * Idempotent: re-running picks up where it left off. Asset rows that
 * already have asset_file_id set are skipped.
 *
 * Usage:
 *   php artisan archive:migrate-to-pool                    # dry-run
 *   php artisan archive:migrate-to-pool --commit           # do the writes
 *   php artisan archive:migrate-to-pool --commit --cleanup # also delete legacy files
 */
class MigrateAssetsToPoolCommand extends Command
{
    protected $signature   = 'archive:migrate-to-pool
                              {--commit  : Actually create pool entries + link asset rows}
                              {--cleanup : After committing, delete the legacy per-crawl files}';

    protected $description = 'Migrate existing per-crawl asset files into the deduped pool';

    public function handle(): int
    {
        $commit  = (bool) $this->option('commit');
        $cleanup = (bool) $this->option('cleanup');

        if (! $commit && $cleanup) {
            $this->error('--cleanup requires --commit');
            return self::FAILURE;
        }

        $disk = Storage::disk('archive');

        // Two distinct candidate sets:
        //   (a) un-migrated rows           — need pool entries created
        //   (b) migrated rows with legacy path still set — need cleanup
        $unmigrated = Asset::query()
            ->whereNull('asset_file_id')
            ->where('storage_path', '!=', '');

        $needsCleanup = Asset::query()
            ->whereNotNull('asset_file_id')
            ->where('storage_path', '!=', '');

        $unmigratedCount = $unmigrated->count();
        $cleanupCount    = $needsCleanup->count();
        $this->info("Found {$unmigratedCount} un-migrated rows + {$cleanupCount} migrated rows still pointing at legacy files.");

        $total = $unmigratedCount + ($cleanup ? $cleanupCount : 0);
        if ($total === 0) {
            $this->info('Nothing to do — fully migrated and cleaned.');
            return self::SUCCESS;
        }

        // Cleanup-only pass: legacy files left over from a previous --commit.
        if ($unmigratedCount === 0 && $cleanup && $cleanupCount > 0) {
            return $this->runCleanupOnly($needsCleanup, $cleanupCount, $disk);
        }

        $query = $unmigrated;
        $total = $unmigratedCount;

        $stats = [
            'rows_processed'   => 0,
            'rows_skipped_404' => 0,
            'unique_files'     => 0,
            'duplicates_found' => 0,
            'bytes_before'     => 0,
            'bytes_after'      => 0,
            'legacy_deleted'   => 0,
            'legacy_bytes_freed' => 0,
        ];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // Track sha256s seen in THIS run (in addition to any already in
        // the pool from a previous partial migration). Lets the dry-run
        // mode correctly count duplicates without needing to write rows.
        $seenSha256 = [];

        $query->orderBy('id')->chunkById(200, function ($assets) use (&$stats, &$seenSha256, $disk, $commit, $cleanup, $bar) {
            foreach ($assets as $asset) {
                $bar->advance();

                if (! $disk->exists($asset->storage_path)) {
                    $stats['rows_skipped_404']++;
                    continue;
                }

                $bytes  = $disk->get($asset->storage_path);
                $size   = strlen($bytes);
                $sha256 = hash('sha256', $bytes);
                $stats['bytes_before'] += $size;
                $stats['rows_processed']++;

                $alreadyInPool = isset($seenSha256[$sha256])
                    || AssetFile::where('sha256', $sha256)->exists();

                if ($alreadyInPool) {
                    $stats['duplicates_found']++;
                } else {
                    $stats['unique_files']++;
                    $stats['bytes_after'] += $size;
                    $seenSha256[$sha256] = true;
                }

                if (! $commit) {
                    continue;   // dry-run — accumulate stats only
                }

                $assetFile = AssetFile::firstOrCreatePool(
                    sha256: $sha256,
                    bytes:  $bytes,
                    mime:   $asset->mime_type,
                    extension: pathinfo($asset->storage_path, PATHINFO_EXTENSION) ?: null,
                );

                $asset->update([
                    'asset_file_id' => $assetFile->id,
                    'size_bytes'    => $size,
                ]);
                $assetFile->addRef();

                if ($cleanup && $asset->storage_path !== $assetFile->storage_path) {
                    if ($disk->exists($asset->storage_path)) {
                        $disk->delete($asset->storage_path);
                        $stats['legacy_deleted']++;
                        $stats['legacy_bytes_freed'] += $size;
                    }
                    // Clear legacy column so future reads go through the pool.
                    $asset->update(['storage_path' => '']);
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['metric', 'value'],
            [
                ['rows processed',           number_format($stats['rows_processed'])],
                ['rows skipped (file 404)',  number_format($stats['rows_skipped_404'])],
                ['unique files (new pool entries)', number_format($stats['unique_files'])],
                ['duplicates (reused pool entries)', number_format($stats['duplicates_found'])],
                ['bytes before (legacy footprint)', $this->humanBytes($stats['bytes_before'])],
                ['bytes after (pool footprint)',    $this->humanBytes($stats['bytes_after'])],
                ['estimated savings',               $this->savingsLine($stats)],
                ['legacy files deleted',            $cleanup ? number_format($stats['legacy_deleted']) : 'n/a (no --cleanup)'],
                ['legacy bytes freed',              $cleanup ? $this->humanBytes($stats['legacy_bytes_freed']) : 'n/a'],
            ]
        );

        if (! $commit) {
            $this->warn('Dry-run only. Re-run with --commit to apply, then --commit --cleanup to delete legacy files.');
        } elseif (! $cleanup) {
            $this->info('Committed. Re-run with --commit --cleanup to delete the legacy per-crawl files.');
        } else {
            $this->info('Migration + cleanup complete.');
        }

        return self::SUCCESS;
    }

    /**
     * Cleanup-only pass: rows are already pool-linked but their legacy
     * per-crawl files are still on disk. Delete those files and clear
     * the storage_path column so future reads go through the pool only.
     */
    protected function runCleanupOnly($query, int $total, $disk): int
    {
        $deleted     = 0;
        $bytesFreed  = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->orderBy('id')->chunkById(200, function ($assets) use ($disk, &$deleted, &$bytesFreed, $bar) {
            foreach ($assets as $asset) {
                $bar->advance();
                if ($asset->storage_path === '' || ! $disk->exists($asset->storage_path)) {
                    $asset->update(['storage_path' => '']);
                    continue;
                }
                $size = $disk->size($asset->storage_path);
                $disk->delete($asset->storage_path);
                $asset->update(['storage_path' => '']);
                $deleted++;
                $bytesFreed += $size;
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf(
            'Cleanup complete. Deleted %d legacy files, freed %s.',
            $deleted, $this->humanBytes($bytesFreed)
        ));

        // Best-effort: remove now-empty per-crawl directories.
        $this->pruneEmptyDirs();

        return self::SUCCESS;
    }

    /** Walk the legacy snapshots/{run_id}/... dirs and remove any that are empty. */
    protected function pruneEmptyDirs(): void
    {
        $disk = Storage::disk('archive');
        foreach ($disk->directories('') as $dir) {
            if ($dir === '_pool') continue;
            $this->pruneRecursive($disk, $dir);
        }
    }

    protected function pruneRecursive($disk, string $dir): bool
    {
        foreach ($disk->directories($dir) as $sub) {
            $this->pruneRecursive($disk, $sub);
        }
        if (count($disk->files($dir)) === 0 && count($disk->directories($dir)) === 0) {
            $disk->deleteDirectory($dir);
            return true;
        }
        return false;
    }

    protected function savingsLine(array $s): string
    {
        $before = $s['bytes_before'];
        if ($before === 0) return '0%';
        $after = $s['bytes_after'];
        $saved = $before - $after;
        $pct   = round($saved / $before * 100, 1);
        return $this->humanBytes($saved) . " ({$pct}% reduction)";
    }

    protected function humanBytes(int $b): string
    {
        if ($b < 1024) return $b . ' B';
        if ($b < 1024 ** 2) return round($b / 1024, 1) . ' KB';
        if ($b < 1024 ** 3) return round($b / 1024 ** 2, 1) . ' MB';
        return round($b / 1024 ** 3, 2) . ' GB';
    }
}
