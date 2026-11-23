<?php

use App\Support\Database\SoftDeleteUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-WS-1 correction: Super Admin is a protected system privilege.
 *
 * Removes the fixed `internal_role` enum column (admin/sales/support/super_admin)
 * so ordinary Internal/Customer roles remain database-managed in P2-WS-3.
 *
 * Data mapping:
 * - internal_role = 'super_admin' → is_super_admin = true
 * - all other internal_role values are dropped (they were code-defined placeholders,
 *   not Permission Master role records). Reassignment belongs to P2-WS-3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('identity_type');
        });

        if (Schema::hasColumn('users', 'internal_role')) {
            DB::update("
                UPDATE users
                SET is_super_admin = CASE
                    WHEN identity_type = 'internal' AND internal_role = 'super_admin' THEN true
                    ELSE false
                END
            ");

            SoftDeleteUniqueIndex::dropCheckConstraintIfExists(
                'users',
                'users_internal_role_consistency_check',
            );

            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['internal_role']);
                $table->dropColumn('internal_role');
            });
        }

        SoftDeleteUniqueIndex::addCheckConstraint(
            'users',
            'users_is_super_admin_internal_only_check',
            "(is_super_admin = false) OR (is_super_admin = true AND identity_type = 'internal')",
        );

        Schema::table('users', function (Blueprint $table) {
            $table->index('is_super_admin');
        });
    }

    public function down(): void
    {
        SoftDeleteUniqueIndex::dropCheckConstraintIfExists(
            'users',
            'users_is_super_admin_internal_only_check',
        );

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_super_admin']);
            $table->string('internal_role', 64)->nullable()->after('identity_type');
        });

        DB::table('users')
            ->where('is_super_admin', true)
            ->update(['internal_role' => 'super_admin']);

        // Non-Super-Admin Internal users lose a concrete role on rollback; they must be
        // reclassified before the historical enum CHECK can be reapplied. Fail loudly
        // rather than inventing admin/sales/support assignments.
        $untypedInternals = DB::table('users')
            ->where('identity_type', 'internal')
            ->where('is_super_admin', false)
            ->whereNull('internal_role')
            ->orderBy('id')
            ->pluck('email');

        if ($untypedInternals->isNotEmpty()) {
            throw new RuntimeException(
                'Rollback blocked: Internal non-Super-Admin users have no enum role to restore. '.
                'Classify or remove before rolling back: '.$untypedInternals->implode(', ')
            );
        }

        SoftDeleteUniqueIndex::addCheckConstraint(
            'users',
            'users_internal_role_consistency_check',
            "
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
            ",
        );

        Schema::table('users', function (Blueprint $table) {
            $table->index('internal_role');
            $table->dropColumn('is_super_admin');
        });
    }
};
