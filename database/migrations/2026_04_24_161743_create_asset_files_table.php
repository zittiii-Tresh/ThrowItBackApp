<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dedup pool table.
 *
 * Each row represents ONE physical file on disk, keyed by the SHA-256 of its
 * bytes. Many `assets` rows (from many snapshots, from many crawl runs) can
 * point at the same `asset_files` row — meaning they share a single physical
 * copy on disk. That's the dedup.
 *
 * `ref_count` is bumped when an Asset row is created and decremented when an
 * Asset row is deleted. When ref_count hits 0, the underlying file on disk
 * can be safely garbage-collected (no other snapshot references it).
 *
 * The `storage_path` is content-addressed: `_pool/{first2}/{next2}/{rest}.{ext}`.
 * Spreading by hash prefix avoids one massive flat directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_files', function (Blueprint $t) {
            $t->id();

            // SHA-256 of the file bytes — the "fingerprint" that determines
            // identity. Two different URLs returning the same bytes share
            // one row here.
            $t->char('sha256', 64)->unique();

            // Path on the `archive` disk. Derived from sha256 but stored
            // explicitly so we don't have to recompute on every read.
            $t->string('storage_path');

            // Useful metadata so we don't have to re-stat the file.
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->string('mime_type')->nullable();

            // How many `assets` rows currently point at this file. When this
            // reaches 0 the physical file can be deleted from disk by the
            // garbage collector.
            $t->unsignedInteger('ref_count')->default(0);

            $t->timestamps();
        });

        // Add the foreign key on the existing `assets` table. Nullable for
        // back-compat — legacy rows that haven't been migrated yet still have
        // their own per-run storage_path until the migration command runs.
        Schema::table('assets', function (Blueprint $t) {
            $t->foreignId('asset_file_id')
              ->nullable()
              ->after('snapshot_id')
              ->constrained('asset_files')
              ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->dropForeign(['asset_file_id']);
            $t->dropColumn('asset_file_id');
        });
        Schema::dropIfExists('asset_files');
    }
};
