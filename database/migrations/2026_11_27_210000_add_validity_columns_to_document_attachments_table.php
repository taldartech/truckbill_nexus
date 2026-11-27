<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->string('document_number', 64)->nullable()->after('label');
            $table->date('issued_on')->nullable()->after('document_number');
            $table->date('expires_on')->nullable()->after('issued_on');
        });
    }

    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropColumn(['document_number', 'issued_on', 'expires_on']);
        });
    }
};
