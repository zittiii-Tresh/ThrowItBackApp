<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds soft-delete (`deleted_at`) to crawl_runs so the retention/manual-delete
 * flow can move runs into the "trash" without immediately freeing disk space.
 *
 * Trash workflow:
 *   1. Admin (or RetentionRunCommand) calls $crawlRun->delete() — sets deleted_at.
 *      The crawl is hidden from normal Crawl History queries but its
 *      snapshots, assets, and pooled files all still exist on disk.
 *   2. After N days (configurable, default 7), TrashPurgeCommand finds
 *      crawl_runs where deleted_at < cutoff and hard-deletes them. That's
 *      when ref_counts drop and orphaned pool files actually get freed.
 *   3. Restore = clear deleted_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_runs', function (Blueprint $t) {
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_runs', function (Blueprint $t) {
            $t->dropSoftDeletes();
        });
    }
};
