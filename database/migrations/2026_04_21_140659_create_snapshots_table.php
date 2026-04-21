<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A `snapshots` row = one page captured during a crawl run. The actual
     * HTML content lives on disk at `html_path` (resolved against the
     * `archive` disk — see config/filesystems.php).
     *
     * Drives:
     *   - Snapshot viewer iframe (User Screen 3)
     *   - Page tab navigation (/, /about, /pricing...)
     *   - Compare/diff dropdown (User Screen 5)
     */
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('crawl_run_id')
                ->constrained('crawl_runs')
                ->cascadeOnDelete();

            // URL captured. varchar(1024) fits query strings and long paths.
            $table->string('url', 1024);

            // Path component only, used by the page-tabs UI (e.g. "/about").
            $table->string('path', 512)->default('/');

            $table->unsignedSmallInteger('status_code')->default(200);

            // <title> text extracted during HTML parse. Shown in the page
            // picker + compare dropdown.
            $table->string('title', 500)->nullable();

            // Relative path under the `archive` disk:
            //   "{site_id}/{run_id}/pages/{sha1}.html"
            $table->string('html_path', 500);

            // Convenience counters for the asset panel sidebar.
            $table->unsignedInteger('asset_count')->default(0);
            $table->unsignedBigInteger('html_bytes')->default(0);

            $table->timestamps();

            $table->index(['crawl_run_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
