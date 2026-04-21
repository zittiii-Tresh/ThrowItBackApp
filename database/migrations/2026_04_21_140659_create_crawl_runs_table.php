<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A `crawl_runs` row = one execution of CrawlSiteJob against a site.
     * Drives:
     *   - Dashboard "Recent crawl runs" (Screen 1)
     *   - Crawl History full log (Screen 4)
     *   - Browse calendar dots — dates with snapshots (User Screen 2)
     */
    public function up(): void
    {
        Schema::create('crawl_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')
                ->constrained('sites')
                ->cascadeOnDelete();

            // Status + trigger source stored as strings (no native enum).
            // Backed by App\Enums\CrawlStatus / TriggerSource casts.
            $table->string('status', 16)->default('queued');
            $table->string('triggered_by', 16)->default('scheduler');

            // Timing + counters filled in as the crawl progresses.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('pages_crawled')->default(0);
            $table->unsignedInteger('assets_downloaded')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);

            // Non-fatal warnings get appended; fatal errors land here before
            // status flips to 'failed'.
            $table->text('error_message')->nullable();

            $table->timestamps();

            // Common access patterns:
            //   - list all runs for a site, newest first
            //   - filter by status (show failed only)
            //   - calendar query: runs for a site on a given date
            $table->index(['site_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_runs');
    }
};
