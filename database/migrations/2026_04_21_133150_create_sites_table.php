<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `sites` table — one row per registered website that SiteArchive
     * will crawl on a schedule. Drives Admin Screens 2 (All sites), 3 (Add
     * site modal), and 5 (Schedules). Related crawl_runs/snapshots/assets
     * tables are added in Phase 3.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();

            // Display name (e.g. "acme.com") + canonical URL to crawl.
            $table->string('name', 120);
            $table->string('base_url', 255);

            // Crawl budget. Defaults match the proposal's sample values.
            $table->unsignedTinyInteger('crawl_depth')->default(2);
            $table->unsignedInteger('max_pages')->default(500);

            // Frequency stored as type + flexible JSON config:
            //   - 'daily'         → {}
            //   - 'every_n_days'  → {"days": 2}
            //   - 'specific_days' → {"days": ["mon","wed","fri"]}
            //   - 'custom_cron'   → {"cron": "0 20 * * *"}
            $table->string('frequency_type', 32)->default('daily');
            $table->json('frequency_config')->nullable();

            // Notification channels — array of "email" / "slack".
            $table->json('notify_channels')->nullable();

            // Paused sites still keep their snapshot history; only future
            // scheduled crawls are suspended.
            $table->boolean('is_active')->default(true);

            // Populated by Phase 3 crawl engine + Phase 4 scheduler.
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'next_run_at']);
            $table->unique('base_url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
