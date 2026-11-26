<?php

namespace App\Domains\Master\TyreLayout;

use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Master\TyreLayout\Enums\TyreAxleConfiguration;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OwnerTyreLayout;
use App\Models\OwnerTyreLayoutAxle;
use App\Models\OwnerTyreLayoutPosition;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Database\CaseInsensitiveLike;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TyreLayoutService
{
    public function __construct(
        private readonly MasterAccess $access = new MasterAccess,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly TyreLayoutPositionDeriver $deriver = new TyreLayoutPositionDeriver,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        User $actor,
        Company $company,
        array $attributes,
        string $remark = 'Create tyre layout',
    ): OwnerTyreLayout {
        $this->access->assertOwnsCompany($actor, $company);
        $branch = $this->access->resolveActorBranch($actor, $company);
        $this->access->assertCanMutate($actor, $company, $branch);

        $normalized = $this->normalizeInput($actor, $attributes, null);

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $normalized, $attributes, $remark): OwnerTyreLayout {
            return $this->access->withTenant($actor, $company, $branch, function () use ($actor, $normalized, $attributes, $remark): OwnerTyreLayout {
                $layout = OwnerTyreLayout::query()->create([
                    'id' => (string) Str::uuid(),
                    'owner_user_id' => $actor->id,
                    'name' => $normalized['name'],
                    'spare_tyre_count' => $normalized['spare'],
                    'drive_tyre_count' => count($this->deriver->derive($normalized['axles'])),
                    'needs_axle_completion' => $normalized['axles'] === [],
                    'is_active' => $attributes['is_active'] ?? true,
                    'sort_order' => $attributes['sort_order'] ?? 0,
                ]);

                $this->persistAxlesAndPositions($layout, $normalized['axles'], $normalized['spare']);

                $this->audit->write(
                    AuditAction::Created,
                    OwnerTyreLayout::class,
                    $layout->id,
                    remark: $remark,
                    before: null,
                    after: $layout->fresh()?->load(['axles', 'positions'])?->toArray(),
                );

                return $layout->fresh(['axles', 'positions']) ?? $layout;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        User $actor,
        OwnerTyreLayout $layout,
        Company $company,
        array $attributes,
        string $remark,
    ): OwnerTyreLayout {
        $this->assertOwnerLayout($actor, $layout);
        $this->access->assertOwnsCompany($actor, $company);
        $branch = $this->access->resolveActorBranch($actor, $company);
        $this->access->assertCanEdit($actor, $company, $branch);

        $hasAxles = array_key_exists('axles', $attributes);
        $name = array_key_exists('name', $attributes)
            ? $this->normalizeName($actor, (string) $attributes['name'], $layout)
            : $layout->name;
        $spareCount = array_key_exists('spare_tyre_count', $attributes)
            ? $this->normalizeSpareCount($attributes['spare_tyre_count'])
            : $layout->spare_tyre_count;
        $axles = $hasAxles
            ? $this->normalizeAxles($attributes['axles'] ?? [])
            : null;

        $spareChanged = array_key_exists('spare_tyre_count', $attributes)
            && $spareCount !== $layout->spare_tyre_count;

        if (($hasAxles || $spareChanged) && Vehicle::query()->where('tyre_layout_id', $layout->id)->exists()) {
            throw ValidationException::withMessages([
                'axles' => 'This tyre layout is assigned to one or more vehicles. Create a new layout instead of changing axle or spare configuration.',
            ]);
        }

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $layout, $name, $spareCount, $axles, $hasAxles, $spareChanged, $attributes, $remark): OwnerTyreLayout {
            return $this->access->withTenant($actor, $company, $branch, function () use ($layout, $name, $spareCount, $axles, $hasAxles, $spareChanged, $attributes, $remark): OwnerTyreLayout {
                $before = $layout->toArray();

                $layout->fill(collect($attributes)->only(['is_active', 'sort_order'])->all());
                $layout->name = $name;
                $layout->spare_tyre_count = $spareCount < 0 ? 0 : $spareCount;

                if ($hasAxles) {
                    $axleDefinitions = $axles ?? [];
                    $layout->needs_axle_completion = $axleDefinitions === [];
                    $layout->drive_tyre_count = count($this->deriver->derive($axleDefinitions));
                    $this->syncAxlesAndPositions($layout, $axleDefinitions, $spareCount);
                } elseif ($spareChanged) {
                    $this->syncSparePositions($layout, $spareCount);
                }

                $layout->save();

                $this->audit->write(
                    AuditAction::Updated,
                    OwnerTyreLayout::class,
                    $layout->id,
                    remark: $remark,
                    before: $before,
                    after: $layout->fresh()?->load(['axles', 'positions'])?->toArray(),
                );

                return $layout->fresh(['axles', 'positions']) ?? $layout;
            });
        });
    }

    public function softDelete(User $actor, OwnerTyreLayout $layout, Company $company, string $remark): void
    {
        $this->assertOwnerLayout($actor, $layout);
        $this->access->assertOwnsCompany($actor, $company);
        $branch = $this->access->resolveActorBranch($actor, $company);
        $this->access->assertCanDelete($actor, $company, $branch);

        $this->auditedTransaction->run(function () use ($actor, $company, $branch, $layout, $remark): void {
            $this->access->withTenant($actor, $company, $branch, function () use ($layout, $remark): void {
                $before = $layout->toArray();
                $layout->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    OwnerTyreLayout::class,
                    $layout->id,
                    remark: $remark,
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @return LengthAwarePaginator<int, OwnerTyreLayout>
     */
    public function search(
        User $actor,
        Company $company,
        Branch $branch,
        ?string $query = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->access->assertCanView($actor, $company, $branch);

        $builder = OwnerTyreLayout::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->with(['axles', 'positions' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($query !== null && trim($query) !== '') {
            CaseInsensitiveLike::whereContains($builder, 'name', $query);
        }

        return $builder->paginate($perPage);
    }

    public function assertForCompany(Company $company, string $layoutId, string $field = 'tyre_layout_id'): void
    {
        $layout = OwnerTyreLayout::query()
            ->whereKey($layoutId)
            ->where('owner_user_id', $company->owner_user_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($layout === null) {
            throw ValidationException::withMessages([
                $field => 'Selected tyre layout is invalid.',
            ]);
        }

        if ($layout->needs_axle_completion) {
            throw ValidationException::withMessages([
                $field => 'Selected tyre layout is incomplete. Complete axle configuration before assigning it to a vehicle.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{name: string, spare: int, axles: list<array{sequence: int, configuration: TyreAxleConfiguration}>}
     */
    private function normalizeInput(User $actor, array $attributes, ?OwnerTyreLayout $existing): array
    {
        $name = $this->normalizeName($actor, (string) ($attributes['name'] ?? ''), $existing);
        $spare = $this->normalizeSpareCount($attributes['spare_tyre_count'] ?? 0);
        $axles = $this->normalizeAxles($attributes['axles'] ?? []);

        return ['name' => $name, 'spare' => $spare, 'axles' => $axles];
    }

    private function normalizeName(User $actor, string $name, ?OwnerTyreLayout $existing): string
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Layout name is required.']);
        }

        $duplicate = OwnerTyreLayout::query()
            ->where('owner_user_id', $actor->id)
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'Tyre layout name already exists for this owner.']);
        }

        return $name;
    }

    private function normalizeSpareCount(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages(['spare_tyre_count' => 'Spare tyre count must be a number.']);
        }

        $count = (int) $value;
        if ($count < 0 || $count > 20) {
            throw ValidationException::withMessages(['spare_tyre_count' => 'Spare tyre count must be between 0 and 20.']);
        }

        return $count;
    }

    /**
     * @return list<array{sequence: int, configuration: TyreAxleConfiguration}>
     */
    private function normalizeAxles(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['axles' => 'Axle configuration is required.']);
        }

        if ($raw === []) {
            return [];
        }

        if (count($raw) > 16) {
            throw ValidationException::withMessages(['axles' => 'A maximum of 16 axles is allowed.']);
        }

        $axles = [];
        $seen = [];

        foreach (array_values($raw) as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(["axles.{$index}" => 'Each axle must be an object.']);
            }

            $sequence = (int) ($row['sequence'] ?? ($index + 1));
            if ($sequence < 1 || $sequence > 16) {
                throw ValidationException::withMessages(["axles.{$index}.sequence" => 'Axle sequence must be between 1 and 16.']);
            }

            if (isset($seen[$sequence])) {
                throw ValidationException::withMessages(["axles.{$index}.sequence" => 'Axle sequence must be unique.']);
            }
            $seen[$sequence] = true;

            $configuration = TyreAxleConfiguration::tryFrom((string) ($row['configuration'] ?? ''));
            if ($configuration === null) {
                throw ValidationException::withMessages(["axles.{$index}.configuration" => 'Axle configuration must be single or dual.']);
            }

            $axles[] = [
                'sequence' => $sequence,
                'configuration' => $configuration,
            ];
        }

        usort($axles, fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return $axles;
    }

    /**
     * @param  list<array{sequence: int, configuration: TyreAxleConfiguration}>  $axles
     */
    private function persistAxlesAndPositions(OwnerTyreLayout $layout, array $axles, int $spareTyreCount): void
    {
        foreach ($axles as $axle) {
            OwnerTyreLayoutAxle::query()->create([
                'id' => (string) Str::uuid(),
                'tyre_layout_id' => $layout->id,
                'sequence' => $axle['sequence'],
                'configuration' => $axle['configuration'],
            ]);
        }

        foreach ($this->deriver->deriveAll($axles, $spareTyreCount) as $position) {
            OwnerTyreLayoutPosition::query()->create([
                'id' => (string) Str::uuid(),
                'tyre_layout_id' => $layout->id,
                ...$position,
                'is_active' => true,
            ]);
        }
    }

    /**
     * Preserve stable position IDs when keys match; deactivate removed keys.
     *
     * @param  list<array{sequence: int, configuration: TyreAxleConfiguration}>  $axles
     */
    private function syncAxlesAndPositions(OwnerTyreLayout $layout, array $axles, int $spareTyreCount): void
    {
        DB::transaction(function () use ($layout, $axles, $spareTyreCount): void {
            $layout->axles()->delete();

            foreach ($axles as $axle) {
                OwnerTyreLayoutAxle::query()->create([
                    'id' => (string) Str::uuid(),
                    'tyre_layout_id' => $layout->id,
                    'sequence' => $axle['sequence'],
                    'configuration' => $axle['configuration'],
                ]);
            }

            $derived = $this->deriver->deriveAll($axles, $spareTyreCount);
            $derivedKeys = collect($derived)->keyBy('position_key');
            $existing = OwnerTyreLayoutPosition::query()
                ->where('tyre_layout_id', $layout->id)
                ->get()
                ->keyBy('position_key');

            foreach ($derived as $definition) {
                $current = $existing->get($definition['position_key']);
                if ($current !== null) {
                    $current->fill([
                        'axle_sequence' => $definition['axle_sequence'],
                        'side' => $definition['side'],
                        'ring' => $definition['ring'],
                        'label' => $definition['label'],
                        'is_active' => true,
                    ]);
                    $current->save();

                    continue;
                }

                OwnerTyreLayoutPosition::query()->create([
                    'id' => (string) Str::uuid(),
                    'tyre_layout_id' => $layout->id,
                    ...$definition,
                    'is_active' => true,
                ]);
            }

            foreach ($existing as $key => $position) {
                if (! $derivedKeys->has($key)) {
                    $position->is_active = false;
                    $position->save();
                }
            }
        });
    }

    private function syncSparePositions(OwnerTyreLayout $layout, int $spareTyreCount): void
    {
        DB::transaction(function () use ($layout, $spareTyreCount): void {
            $derived = collect($this->deriver->deriveSpares($spareTyreCount))->keyBy('position_key');
            $existing = OwnerTyreLayoutPosition::query()
                ->where('tyre_layout_id', $layout->id)
                ->where('axle_sequence', 0)
                ->get()
                ->keyBy('position_key');

            foreach ($derived as $definition) {
                $current = $existing->get($definition['position_key']);
                if ($current !== null) {
                    $current->fill([
                        'side' => $definition['side'],
                        'ring' => $definition['ring'],
                        'label' => $definition['label'],
                        'is_active' => true,
                    ]);
                    $current->save();

                    continue;
                }

                OwnerTyreLayoutPosition::query()->create([
                    'id' => (string) Str::uuid(),
                    'tyre_layout_id' => $layout->id,
                    ...$definition,
                    'is_active' => true,
                ]);
            }

            foreach ($existing as $key => $position) {
                if (! $derived->has($key)) {
                    $position->is_active = false;
                    $position->save();
                }
            }
        });
    }

    private function assertOwnerLayout(User $actor, OwnerTyreLayout $layout): void
    {
        if ((int) $layout->owner_user_id !== (int) $actor->id) {
            throw new MasterDomainException('Tyre layout does not belong to this owner.');
        }
    }
}
