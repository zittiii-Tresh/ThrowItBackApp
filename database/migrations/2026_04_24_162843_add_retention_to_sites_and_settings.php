<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds storage retention controls.
 *
 * sites.retention_months
 *   Per-site override. NULL means "use global default". Allowed values:
 *   1, 2, 3, 6, 12 (months) or 0 to mean "keep forever". The dropdown in
 *   the Site Edit form maps directly to this.
 *
 * settings.default_retention_months
 *   The default that sites without an override inherit. Default = 3.
 *
 * settings.cleanup_hour
 *   Local hour (0-23) when the nightly cleanup job runs. Default = 3 (03:00).
 *
 * settings.cleanup_last_run_at
 *   Timestamp of the last successful cleanup run. Lets the dashboard
 *   show "Last cleanup: 3 hours ago".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $t) {
            $t->unsignedTinyInteger('retention_months')->nullable()->after('crawl_depth');
        });

        Schema::table('settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('default_retention_months')->default(3);
            $t->unsignedTinyInteger('cleanup_hour')->default(3);
            $t->timestamp('cleanup_last_run_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $t) {
            $t->dropColumn('retention_months');
        });
        Schema::table('settings', function (Blueprint $t) {
            $t->dropColumn(['default_retention_months', 'cleanup_hour', 'cleanup_last_run_at']);
        });
    }
};
