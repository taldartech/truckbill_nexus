<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Private stored file metadata (ADR-008 / WS-5).
     */
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('disk')->default('private_files');
            $table->string('path');
            $table->string('original_name');
            $table->string('extension', 16);
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64)->nullable();

            $table->string('company_id');
            $table->string('branch_id')->nullable();
            $table->string('owner_id')->nullable();

            $table->string('scan_status', 32);
            $table->text('scan_message')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->timestampTz('available_at')->nullable();

            $table->uuid('replaces_file_id')->nullable();
            $table->timestampTz('replaced_at')->nullable();
            $table->boolean('pending_cleanup')->default(false);

            $table->timestamps();

            $table->index(['company_id', 'branch_id']);
            $table->index('scan_status');
            $table->index('replaces_file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
