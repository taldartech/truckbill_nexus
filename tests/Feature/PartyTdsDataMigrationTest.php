<?php

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\TdsRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * @return array{declaration: Migration, bank: Migration}
 */
function partyTdsMigrations(): array
{
    return [
        'declaration' => require database_path('migrations/2026_11_27_180000_remove_declaration_type_and_normalize_pan_status.php'),
        'bank' => require database_path('migrations/2026_11_27_181000_add_party_short_name_and_bank_details.php'),
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyRoleAttributes(PartyRole $role, array $attributes): void
{
    DB::table('party_roles')->where('id', $role->id)->update(['role_attributes' => json_encode($attributes)]);
}

test('forward migrations normalize legacy PAN status, declaration type and role bank details', function () {
    $transporter = Party::factory()->create(['name' => 'Konkan Transporters']);
    $unknownPan = Party::factory()->create(['name' => 'Unknown PAN Transporter']);
    $driver = Party::factory()->create(['name' => 'Ravi Driver']);
    $transporterRole = PartyRole::factory()->create(['party_id' => $transporter->id, 'company_id' => $transporter->company_id, 'role' => 'transporter']);
    $unknownRole = PartyRole::factory()->create(['party_id' => $unknownPan->id, 'company_id' => $unknownPan->company_id, 'role' => 'transporter']);
    $driverRole = PartyRole::factory()->create(['party_id' => $driver->id, 'company_id' => $driver->company_id, 'role' => 'driver']);
    $rule = TdsRule::factory()->create();

    ['declaration' => $declarationMigration, 'bank' => $bankMigration] = partyTdsMigrations();
    $bankMigration->down();
    $declarationMigration->down();

    legacyRoleAttributes($transporterRole, [
        'pan_status' => 'valid',
        'declaration' => true,
        'declaration_type' => 'owner',
        'bank_account' => '30112233445566',
        'short_name' => 'KONKAN SBI',
        'beneficiary_id' => 'BEN-7',
    ]);
    legacyRoleAttributes($unknownRole, ['pan_status' => 'pending', 'declaration' => false]);
    legacyRoleAttributes($driverRole, ['driver_type' => 'market', 'bank_name' => 'SBI', 'bank_account_number' => '1234', 'ifsc' => 'SBIN0001234']);
    DB::table('tds_rules')->where('id', $rule->id)->update(['conditions' => json_encode([
        ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'invalid'],
        ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'inactive'],
        ['field' => 'declaration_type', 'operator' => 'eq', 'value' => 'owner'],
    ])]);

    $declarationMigration->up();
    $bankMigration->up();

    expect($transporterRole->fresh()->role_attributes)->toEqual(['pan_status' => 'active', 'declaration' => true])
        ->and($unknownRole->fresh()->role_attributes)->toEqual(['declaration' => false])
        ->and($driverRole->fresh()->role_attributes)->toEqual(['driver_type' => 'market'])
        ->and($rule->fresh()->conditions)->toEqual([['field' => 'pan_status', 'operator' => 'eq', 'value' => 'inactive']]);

    expect($transporter->fresh())
        ->short_name->toBe('Konkan Transporters')
        ->bank_account_number->toBe('30112233445566')
        ->bank_short_name->toBe('KONKAN SBI')
        ->bank_beneficiary_id->toBe('BEN-7')
        ->and($driver->fresh())
        ->bank_name->toBe('SBI')
        ->bank_account_number->toBe('1234')
        ->bank_ifsc->toBe('SBIN0001234');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Schema rollback inside the test transaction requires transactional DDL (PostgreSQL).');
