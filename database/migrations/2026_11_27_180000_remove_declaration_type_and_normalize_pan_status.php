<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules §25: TDS uses "declaration received" only and PAN status is Active/Inactive.
 *
 * - Legacy PAN status "valid" (operative PAN) becomes "active"; "invalid" (inoperative PAN,
 *   same 20% treatment) becomes "inactive". Any other value is removed rather than guessed.
 * - Declaration type is removed from transporter roles and TDS rule conditions.
 * - Lorry Hire declaration type is preserved on its immutable TDS snapshot before the
 *   transactional column is dropped.
 */
return new class extends Migration
{
    private const PAN_STATUS_MAP = [
        'active' => 'active',
        'valid' => 'active',
        'inactive' => 'inactive',
        'invalid' => 'inactive',
    ];

    public function up(): void
    {
        $this->normalizeTransporterRoles();
        $this->normalizeTdsRules();

        DB::table('lorry_hires')->orderBy('id')->each(function (object $hire): void {
            DB::table('lorry_hire_tds_snapshots')
                ->where('lorry_hire_id', $hire->id)
                ->whereNull('declaration_type')
                ->update(['declaration_type' => $hire->declaration_type]);
        });

        Schema::table('lorry_hires', function (Blueprint $table): void {
            $table->dropColumn('declaration_type');
        });
    }

    public function down(): void
    {
        Schema::table('lorry_hires', function (Blueprint $table): void {
            $table->string('declaration_type', 16)->nullable()->after('transporter_party_id');
        });

        DB::table('lorry_hire_tds_snapshots')->whereNotNull('declaration_type')->orderBy('id')
            ->each(function (object $snapshot): void {
                DB::table('lorry_hires')->where('id', $snapshot->lorry_hire_id)
                    ->update(['declaration_type' => $snapshot->declaration_type]);
            });
    }

    private function normalizeTransporterRoles(): void
    {
        DB::table('party_roles')->where('role', 'transporter')->orderBy('id')
            ->each(function (object $role): void {
                $attributes = json_decode((string) $role->role_attributes, true);
                if (! is_array($attributes)) {
                    return;
                }

                unset($attributes['declaration_type']);

                $status = strtolower(trim((string) ($attributes['pan_status'] ?? '')));
                if (isset(self::PAN_STATUS_MAP[$status])) {
                    $attributes['pan_status'] = self::PAN_STATUS_MAP[$status];
                } else {
                    unset($attributes['pan_status']);
                }

                DB::table('party_roles')->where('id', $role->id)
                    ->update(['role_attributes' => json_encode($attributes)]);
            });
    }

    private function normalizeTdsRules(): void
    {
        DB::table('tds_rules')->orderBy('id')->each(function (object $rule): void {
            $conditions = json_decode((string) $rule->conditions, true);
            if (! is_array($conditions)) {
                return;
            }

            $normalized = [];
            foreach ($conditions as $condition) {
                if (! is_array($condition) || ($condition['field'] ?? null) === 'declaration_type') {
                    continue;
                }

                if ($condition['field'] === 'pan_status') {
                    $condition['value'] = is_array($condition['value'] ?? null)
                        ? array_values(array_unique(array_map($this->mapPanStatus(...), $condition['value'])))
                        : $this->mapPanStatus($condition['value'] ?? null);
                }

                if (! in_array($condition, $normalized, true)) {
                    $normalized[] = $condition;
                }
            }

            DB::table('tds_rules')->where('id', $rule->id)
                ->update(['conditions' => json_encode($normalized)]);
        });
    }

    private function mapPanStatus(mixed $value): mixed
    {
        return is_string($value) ? (self::PAN_STATUS_MAP[strtolower(trim($value))] ?? $value) : $value;
    }
};
