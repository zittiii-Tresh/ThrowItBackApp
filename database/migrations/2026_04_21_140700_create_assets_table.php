<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An `assets` row = one image / stylesheet / script / font captured
     * alongside a snapshot's HTML. Populated by App\Services\AssetDownloader
     * during the crawl.
     *
     * Drives the Assets panel (User Screen 4) where users filter by type
     * and download individual files.
     *
     * Note: the same asset URL often appears across many snapshots (e.g.
     * the site-wide logo). We store per-snapshot rows so the panel shows
     * exactly what loaded on each page, but the on-disk file is shared
     * by content hash so we don't write the logo 50 times.
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('snapshot_id')
                ->constrained('snapshots')
                ->cascadeOnDelete();

            $table->string('url', 1024);

            // image/stylesheet/javascript/font/other (AssetType enum).
            $table->string('type', 16);

            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Relative path on the `archive` disk:
            //   "{site_id}/{run_id}/assets/{sha1}.{ext}"
            $table->string('storage_path', 500);

            // HTTP status of the asset fetch. 0 means "download failed".
            $table->unsignedSmallInteger('status_code')->default(200);

            $table->timestamps();

            $table->index(['snapshot_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
