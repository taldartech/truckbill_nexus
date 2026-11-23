<?php

namespace App\Domains\Master\Vehicle;

use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Support\MasterAccess;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VehicleService
{
    public function __construct(
        private readonly MasterAccess $access = new MasterAccess,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, Company $company, ?Branch $branch, array $attributes, string $remark = 'Create vehicle'): Vehicle
    {
        $this->access->assertCanMutate($actor, $company, $branch);
        $this->assertBranchBelongs($company, $branch);
        $this->assertRegistrationUnique($company, (string) $attributes['registration_number']);

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $attributes, $remark): Vehicle {
            return $this->access->withTenant($actor, $company, $branch, function () use ($company, $branch, $attributes, $remark): Vehicle {
                $vehicle = Vehicle::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch?->id,
                    'registration_number' => strtoupper(trim((string) $attributes['registration_number'])),
                    'vehicle_type' => $attributes['vehicle_type'] ?? null,
                    'make' => $attributes['make'] ?? null,
                    'model' => $attributes['model'] ?? null,
                    'manufacture_year' => $attributes['manufacture_year'] ?? null,
                    'chassis_number' => $attributes['chassis_number'] ?? null,
                    'engine_number' => $attributes['engine_number'] ?? null,
                    'capacity_mt' => $attributes['capacity_mt'] ?? null,
                    'is_active' => $attributes['is_active'] ?? true,
                    'primary_driver_party_id' => $attributes['primary_driver_party_id'] ?? null,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    Vehicle::class,
                    $vehicle->id,
                    remark: $remark,
                    before: null,
                    after: $vehicle->toArray(),
                );

                return $vehicle;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Vehicle $vehicle, array $attributes, string $remark): Vehicle
    {
        $company = $vehicle->company()->firstOrFail();
        $branch = $vehicle->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($vehicle->branch_id)->first()
            : null;

        $this->access->assertCanEdit($actor, $company, $branch);

        if (isset($attributes['registration_number'])) {
            $this->assertRegistrationUnique(
                $company,
                (string) $attributes['registration_number'],
                $vehicle->id,
            );
        }

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $vehicle, $attributes, $remark): Vehicle {
            return $this->access->withTenant($actor, $company, $branch, function () use ($vehicle, $attributes, $remark): Vehicle {
                $before = $vehicle->toArray();
                $vehicle->fill(collect($attributes)->only([
                    'registration_number',
                    'vehicle_type',
                    'make',
                    'model',
                    'manufacture_year',
                    'chassis_number',
                    'engine_number',
                    'capacity_mt',
                    'is_active',
                    'primary_driver_party_id',
                ])->all());

                if ($vehicle->isDirty('registration_number')) {
                    $vehicle->registration_number = strtoupper(trim((string) $vehicle->registration_number));
                }

                $vehicle->save();

                $this->audit->write(
                    AuditAction::Updated,
                    Vehicle::class,
                    $vehicle->id,
                    remark: $remark,
                    before: $before,
                    after: $vehicle->fresh()?->toArray(),
                );

                return $vehicle->fresh() ?? $vehicle;
            });
        });
    }

    public function softDelete(User $actor, Vehicle $vehicle, string $remark): void
    {
        $company = $vehicle->company()->firstOrFail();
        $branch = $vehicle->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($vehicle->branch_id)->first()
            : null;

        $this->access->assertCanDelete($actor, $company, $branch);

        $this->auditedTransaction->run(function () use ($actor, $company, $branch, $vehicle, $remark): void {
            $this->access->withTenant($actor, $company, $branch, function () use ($vehicle, $remark): void {
                $before = $vehicle->toArray();
                $vehicle->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    Vehicle::class,
                    $vehicle->id,
                    remark: $remark,
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @return LengthAwarePaginator<int, Vehicle>
     */
    public function search(
        User $actor,
        Company $company,
        Branch $branch,
        ?string $query = null,
        ?string $vehicleType = null,
        ?bool $isActive = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->access->assertCanView($actor, $company, $branch);

        $builder = Vehicle::query()
            ->visibleInBranch($company->id, $branch->id)
            ->orderBy('registration_number');

        if ($query !== null && trim($query) !== '') {
            $term = '%'.$this->escapeLike(trim($query)).'%';
            $builder->where(function ($inner) use ($term): void {
                $inner->where('registration_number', 'ilike', $term)
                    ->orWhere('make', 'ilike', $term)
                    ->orWhere('model', 'ilike', $term)
                    ->orWhere('chassis_number', 'ilike', $term);
            });
        }

        if ($vehicleType !== null && $vehicleType !== '') {
            $builder->where('vehicle_type', $vehicleType);
        }

        if ($isActive !== null) {
            $builder->where('is_active', $isActive);
        }

        return $builder->paginate($perPage);
    }

    private function assertRegistrationUnique(Company $company, string $registration, ?string $ignoreId = null): void
    {
        $normalized = strtoupper(trim($registration));
        if ($normalized === '') {
            throw ValidationException::withMessages(['registration_number' => 'Registration number is required.']);
        }

        $exists = Vehicle::query()
            ->where('company_id', $company->id)
            ->where('registration_number', $normalized)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'registration_number' => 'A vehicle with this registration already exists in the company.',
            ]);
        }
    }

    private function assertBranchBelongs(Company $company, ?Branch $branch): void
    {
        if ($branch !== null && $branch->company_id !== $company->id) {
            throw new MasterDomainException('Branch does not belong to company.');
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
