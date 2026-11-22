<?php

namespace App\Domains\Master\Station;

use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Support\MasterAccess;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\Station;
use App\Models\StationDefaultTransporter;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owner-shared Station Master (Rules §5).
 *
 * Default transporter mappings are company/branch scoped and do not rewrite
 * previously saved transaction data (transactions not implemented in Phase 3).
 */
final class StationService
{
    public function __construct(
        private readonly MasterAccess $access = new MasterAccess,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, Company $company, array $attributes, string $remark = 'Create station'): Station
    {
        $this->access->assertOwnsCompany($actor, $company);
        $branch = $this->access->resolveActorBranch($actor, $company);
        $this->access->assertCanMutate($actor, $company, $branch);

        $code = strtoupper(trim((string) ($attributes['code'] ?? '')));
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages([
                'code' => $code === '' ? 'Station code is required.' : null,
                'name' => $name === '' ? 'Station name is required.' : null,
            ]);
        }

        if (Station::query()->where('owner_user_id', $actor->id)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'Station code already exists for this owner.']);
        }

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $attributes, $code, $name, $remark): Station {
            return $this->access->withTenant($actor, $company, $branch, function () use ($actor, $attributes, $code, $name, $remark): Station {
                $station = Station::query()->create([
                    'id' => (string) Str::uuid(),
                    'owner_user_id' => $actor->id,
                    'code' => $code,
                    'name' => $name,
                    'state' => $attributes['state'] ?? null,
                    'city' => $attributes['city'] ?? null,
                    'is_active' => $attributes['is_active'] ?? true,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    Station::class,
                    $station->id,
                    remark: $remark,
                    before: null,
                    after: $station->toArray(),
                );

                return $station;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Station $station, Company $company, array $attributes, string $remark): Station
    {
        $this->assertOwnerStation($actor, $station);
        $this->access->assertOwnsCompany($actor, $company);
        $branch = $this->access->resolveActorBranch($actor, $company);
        $this->access->assertCanEdit($actor, $company, $branch);

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $station, $attributes, $remark): Station {
            return $this->access->withTenant($actor, $company, $branch, function () use ($station, $attributes, $remark): Station {
                $before = $station->toArray();
                $station->fill(collect($attributes)->only(['name', 'state', 'city', 'is_active'])->all());
                $station->save();

                $this->audit->write(
                    AuditAction::Updated,
                    Station::class,
                    $station->id,
                    remark: $remark,
                    before: $before,
                    after: $station->fresh()?->toArray(),
                );

                return $station->fresh() ?? $station;
            });
        });
    }

    public function softDelete(User $actor, Station $station, Company $company, string $remark): void
    {
        $this->assertOwnerStation($actor, $station);
        $this->access->assertOwnsCompany($actor, $company);
        $branch = $this->access->resolveActorBranch($actor, $company);
        $this->access->assertCanDelete($actor, $company, $branch);

        $this->auditedTransaction->run(function () use ($actor, $company, $branch, $station, $remark): void {
            $this->access->withTenant($actor, $company, $branch, function () use ($station, $remark): void {
                $before = $station->toArray();
                $station->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    Station::class,
                    $station->id,
                    remark: $remark,
                    before: $before,
                    after: null,
                );
            });
        });
    }

    public function setDefaultTransporter(
        User $actor,
        Station $station,
        Company $company,
        ?Branch $branch,
        ?Party $transporter,
        string $remark,
    ): StationDefaultTransporter {
        $this->assertOwnerStation($actor, $station);
        $this->access->assertOwnsCompany($actor, $company);
        $this->access->assertCanEdit($actor, $company, $branch);

        if ((int) $company->owner_user_id !== (int) $station->owner_user_id) {
            throw new MasterDomainException('Station is not shared with this company owner.');
        }

        if ($branch !== null && $branch->company_id !== $company->id) {
            throw new MasterDomainException('Branch does not belong to company.');
        }

        if ($transporter !== null && $transporter->company_id !== $company->id) {
            throw new MasterDomainException('Transporter party must belong to the company.');
        }

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $station, $transporter, $remark): StationDefaultTransporter {
            return $this->access->withTenant($actor, $company, $branch, function () use ($station, $company, $branch, $transporter, $remark): StationDefaultTransporter {
                $existing = StationDefaultTransporter::query()
                    ->where('station_id', $station->id)
                    ->where('company_id', $company->id)
                    ->where(function ($q) use ($branch): void {
                        if ($branch === null) {
                            $q->whereNull('branch_id');
                        } else {
                            $q->where('branch_id', $branch->id);
                        }
                    })
                    ->first();

                $before = $existing?->toArray();

                if ($existing === null) {
                    $existing = StationDefaultTransporter::query()->create([
                        'id' => (string) Str::uuid(),
                        'station_id' => $station->id,
                        'company_id' => $company->id,
                        'branch_id' => $branch?->id,
                        'default_transporter_party_id' => $transporter?->id,
                    ]);
                } else {
                    $existing->default_transporter_party_id = $transporter?->id;
                    $existing->save();
                }

                $this->audit->write(
                    $before === null ? AuditAction::Created : AuditAction::Updated,
                    StationDefaultTransporter::class,
                    $existing->id,
                    remark: $remark,
                    before: $before,
                    after: $existing->fresh()?->toArray(),
                );

                return $existing->fresh() ?? $existing;
            });
        });
    }

    /**
     * @return LengthAwarePaginator<int, Station>
     */
    public function search(User $actor, Company $company, Branch $branch, ?string $query = null, int $perPage = 15): LengthAwarePaginator
    {
        $this->access->assertCanView($actor, $company, $branch);

        $builder = Station::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->orderBy('name');

        if ($query !== null && trim($query) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($query)).'%';
            $builder->where(function ($inner) use ($term): void {
                $inner->where('name', 'ilike', $term)
                    ->orWhere('code', 'ilike', $term)
                    ->orWhere('city', 'ilike', $term);
            });
        }

        return $builder->paginate($perPage);
    }

    private function assertOwnerStation(User $actor, Station $station): void
    {
        if ((int) $station->owner_user_id !== (int) $actor->id) {
            throw new MasterDomainException('Station does not belong to this owner.');
        }
    }
}
