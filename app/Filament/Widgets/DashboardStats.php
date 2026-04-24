<?php

namespace App\Filament\Widgets;

use App\Enums\CrawlStatus;
use App\Models\AssetFile;
use App\Models\CrawlRun;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Snapshot;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard Screen 1 — four stat cards across the top:
 *   Total sites | Snapshots (30d) | Storage used | Failed crawls
 *
 * Numbers come directly from the DB — no caching yet since sites/runs
 * tables stay small. Add a `->cacheable(60)` in Phase 7 if needed.
 */
class DashboardStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Sites — active only (paused don't count toward "stuff we're archiving").
        $totalSites = Site::active()->count();

        // Snapshots in the last 30 days across all sites.
        $recentSnapshots = Snapshot::where('created_at', '>=', now()->subDays(30))->count();

        // Real disk usage = pool bytes (deduped) + un-migrated legacy bytes.
        $poolBytes   = (int) AssetFile::sum('size_bytes');
        $legacyBytes = (int) \App\Models\Asset::whereNull('asset_file_id')
            ->where('storage_path', '!=', '')->sum('size_bytes');
        $totalBytes  = $poolBytes + $legacyBytes;

        // Storage budget (GB) → bytes for the % calc.
        $budgetGb     = (int) (Setting::current()->storage_limit_gb ?? 50);
        $budgetBytes  = $budgetGb * 1024 ** 3;
        $usagePct     = $budgetBytes > 0 ? min(100, round($totalBytes / $budgetBytes * 100)) : 0;

        // Color tier: green < 70%, amber 70-89%, red 90%+.
        $storageColor = $usagePct >= 90 ? 'danger'
                      : ($usagePct >= 70 ? 'warning' : 'success');

        $storageHuman = $this->humanBytes($totalBytes);

        // Failed crawls in the last 30 days — highlighted red when non-zero.
        $failedCount = CrawlRun::where('status', CrawlStatus::Failed)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return [
            Stat::make('Total sites', $totalSites)
                ->description('Active, scheduled')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('primary'),

            Stat::make('Snapshots (30d)', number_format($recentSnapshots))
                ->description('Pages captured this month')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('info'),

            Stat::make('Storage used', $storageHuman)
                ->description("{$usagePct}% of {$budgetGb} GB budget")
                ->descriptionIcon('heroicon-m-circle-stack')
                ->color($storageColor),

            Stat::make('Failed crawls', $failedCount)
                ->description($failedCount > 0 ? 'Needs attention' : 'All healthy')
                ->descriptionIcon($failedCount > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($failedCount > 0 ? 'danger' : 'success'),
        ];
    }

    protected function humanBytes(int $b): string
    {
        if ($b < 1024)         return $b . ' B';
        if ($b < 1024 ** 2)    return round($b / 1024, 1) . ' KB';
        if ($b < 1024 ** 3)    return round($b / 1024 ** 2, 1) . ' MB';
        return round($b / 1024 ** 3, 2) . ' GB';
    }
}
