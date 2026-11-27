<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules §25: every Party has a general short name, and beneficiary bank details belong to
 * the Party rather than to individual role attributes.
 *
 * Existing short names start from the Party name. Transporter (bank_account, short_name,
 * beneficiary_id) and driver (bank_name, bank_account_number, ifsc) role bank attributes
 * move to Party columns; the transporter "short_name" was always the bank short name.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private const ROLE_BANK_KEYS = [
        'transporter' => [
            'bank_account' => 'bank_account_number',
            'short_name' => 'bank_short_name',
            'beneficiary_id' => 'bank_beneficiary_id',
        ],
        'driver' => [
            'bank_name' => 'bank_name',
            'bank_account_number' => 'bank_account_number',
            'ifsc' => 'bank_ifsc',
        ],
    ];

    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->string('short_name')->nullable()->after('name');
            $table->string('bank_name')->nullable()->after('address');
            $table->string('bank_account_holder_name')->nullable()->after('bank_name');
            $table->string('bank_account_number', 64)->nullable()->after('bank_account_holder_name');
            $table->string('bank_ifsc', 16)->nullable()->after('bank_account_number');
            $table->string('bank_short_name')->nullable()->after('bank_ifsc');
            $table->string('bank_beneficiary_id', 64)->nullable()->after('bank_short_name');
        });

        DB::table('parties')->update(['short_name' => DB::raw('name')]);

        Schema::table('parties', function (Blueprint $table): void {
            $table->string('short_name')->nullable(false)->change();
            $table->index(['company_id', 'short_name']);
        });

        foreach (self::ROLE_BANK_KEYS as $role => $keyMap) {
            DB::table('party_roles')->where('role', $role)->orderBy('id')
                ->each(function (object $partyRole) use ($keyMap): void {
                    $attributes = json_decode((string) $partyRole->role_attributes, true);
                    if (! is_array($attributes)) {
                        return;
                    }

                    $party = DB::table('parties')->where('id', $partyRole->party_id)->first();
                    $bank = [];
                    foreach ($keyMap as $roleKey => $column) {
                        $value = trim((string) ($attributes[$roleKey] ?? ''));
                        unset($attributes[$roleKey]);
                        if ($value !== '' && $party !== null && blank($party->{$column})) {
                            $bank[$column] = $value;
                        }
                    }

                    if ($bank !== []) {
                        DB::table('parties')->where('id', $partyRole->party_id)->update($bank);
                    }

                    DB::table('party_roles')->where('id', $partyRole->id)
                        ->update(['role_attributes' => json_encode($attributes)]);
                });
        }
    }

    public function down(): void
    {
        foreach (self::ROLE_BANK_KEYS as $role => $keyMap) {
            DB::table('party_roles')->where('role', $role)->orderBy('id')
                ->each(function (object $partyRole) use ($keyMap): void {
                    $party = DB::table('parties')->where('id', $partyRole->party_id)->first();
                    $attributes = json_decode((string) $partyRole->role_attributes, true);
                    if ($party === null || ! is_array($attributes)) {
                        return;
                    }

                    foreach ($keyMap as $roleKey => $column) {
                        if (filled($party->{$column})) {
                            $attributes[$roleKey] = $party->{$column};
                        }
                    }

                    DB::table('party_roles')->where('id', $partyRole->id)
                        ->update(['role_attributes' => json_encode($attributes)]);
                });
        }

        Schema::table('parties', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'short_name']);
            $table->dropColumn([
                'short_name',
                'bank_name',
                'bank_account_holder_name',
                'bank_account_number',
                'bank_ifsc',
                'bank_short_name',
                'bank_beneficiary_id',
            ]);
        });
    }
};
