<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-WS-1: Global identity type boundaries (ADR-005 technical schema).
 *
 * Existing rows cannot be auto-classified as Internal or Customer.
 * The migration refuses to proceed while untyped users remain.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('identity_type', 32)->nullable()->after('email');
            $table->string('internal_role', 64)->nullable()->after('identity_type');
            $table->timestamp('deactivated_at')->nullable()->after('remember_token');
            $table->softDeletes();
        });

        $untypedEmails = DB::table('users')
            ->whereNull('identity_type')
            ->orderBy('id')
            ->pluck('email');

        if ($untypedEmails->isNotEmpty()) {
            throw new RuntimeException(
                'P2-WS-1 cannot auto-classify existing users as Internal or Customer. '.
                'Classify or remove these identities before re-running the migration: '.
                $untypedEmails->implode(', ')
            );
        }

        DB::statement('ALTER TABLE users ALTER COLUMN identity_type SET NOT NULL');

        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT users_identity_type_check
            CHECK (identity_type IN ('internal', 'customer'))
        ");

        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT users_internal_role_consistency_check
            CHECK (
                (identity_type = 'customer' AND internal_role IS NULL)
                OR (
                    identity_type = 'internal'
                    AND internal_role IN (
                        'super_admin',
                        'admin',
                        'sales_executive',
                        'support_executive'
                    )
                )
            )
        ");

        Schema::table('users', function (Blueprint $table) {
            $table->index('identity_type');
            $table->index('internal_role');
            $table->index('deactivated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_internal_role_consistency_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_identity_type_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['identity_type']);
            $table->dropIndex(['internal_role']);
            $table->dropIndex(['deactivated_at']);
            $table->dropSoftDeletes();
            $table->dropColumn(['identity_type', 'internal_role', 'deactivated_at']);
        });
    }
};
