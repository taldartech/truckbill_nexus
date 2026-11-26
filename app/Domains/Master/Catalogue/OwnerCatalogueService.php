<?php

namespace App\Domains\Master\Catalogue;

use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Support\MasterAccess;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
use App\Models\PaymentMode;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Database\CaseInsensitiveLike;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OwnerCatalogueService
{
    public function __construct(
        private readonly MasterAccess $access = new MasterAccess,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        User $actor,
        Company $company,
        OwnerCatalogueType $type,
        array $attributes,
        string $remark = 'Create catalogue item',
    ): OwnerCatalogueItem {
        $this->access->assertOwnsCompany($actor, $company);
        $branch = $this->access->resolveActorBranch($actor, $company);
        $this->access->assertCanMutate($actor, $company, $branch);

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Name is required.']);
        }

        $this->assertUniqueName($actor, $type, $name);

        $code = null;
        if ($type->usesCode()) {
            $code = strtoupper(trim((string) ($attributes['code'] ?? '')));
            if ($code === '') {
                throw ValidationException::withMessages(['code' => 'Code is required.']);
            }
            $this->assertUniqueCode($actor, $type, $code);
        }

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $type, $attributes, $code, $name, $remark): OwnerCatalogueItem {
            return $this->access->withTenant($actor, $company, $branch, function () use ($actor, $type, $attributes, $code, $name, $remark): OwnerCatalogueItem {
                $item = OwnerCatalogueItem::query()->create([
                    'id' => (string) Str::uuid(),
                    'owner_user_id' => $actor->id,
                    'catalogue_type' => $type->value,
                    'code' => $code,
                    'name' => $name,
                    'attributes' => $attributes['attributes'] ?? null,
                    'is_active' => $attributes['is_active'] ?? true,
                    'sort_order' => $attributes['sort_order'] ?? 0,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    OwnerCatalogueItem::class,
                    $item->id,
                    remark: $remark,
                    before: null,
                    after: $item->toArray(),
                );

                return $item;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        User $actor,
        OwnerCatalogueItem $item,
        Company $company,
        array $attributes,
        string $remark,
    ): OwnerCatalogueItem {
        $this->assertOwnerItem($actor, $item);
        $this->access->assertOwnsCompany($actor, $company);
        $branch = $this->access->resolveActorBranch($actor, $company);
        $this->access->assertCanEdit($actor, $company, $branch);

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $item, $attributes, $remark): OwnerCatalogueItem {
            return $this->access->withTenant($actor, $company, $branch, function () use ($actor, $item, $attributes, $remark): OwnerCatalogueItem {
                $before = $item->toArray();

                if (array_key_exists('name', $attributes)) {
                    $name = trim((string) $attributes['name']);
                    if ($name === '') {
                        throw ValidationException::withMessages(['name' => 'Name is required.']);
                    }
                    $type = OwnerCatalogueType::from($item->catalogue_type);
                    $this->assertUniqueName($actor, $type, $name, $item);
                    $item->name = $name;
                }

                $item->fill(collect($attributes)->only(['attributes', 'is_active', 'sort_order'])->all());
                $item->save();

                $this->audit->write(
                    AuditAction::Updated,
                    OwnerCatalogueItem::class,
                    $item->id,
                    remark: $remark,
                    before: $before,
                    after: $item->fresh()?->toArray(),
                );

                return $item->fresh() ?? $item;
            });
        });
    }

    public function softDelete(User $actor, OwnerCatalogueItem $item, Company $company, string $remark): void
    {
        $this->assertOwnerItem($actor, $item);
        $this->access->assertOwnsCompany($actor, $company);
        $branch = $this->access->resolveActorBranch($actor, $company);
        $this->access->assertCanDelete($actor, $company, $branch);

        $this->auditedTransaction->run(function () use ($actor, $company, $branch, $item, $remark): void {
            $this->access->withTenant($actor, $company, $branch, function () use ($item, $remark): void {
                $before = $item->toArray();
                $item->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    OwnerCatalogueItem::class,
                    $item->id,
                    remark: $remark,
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @return LengthAwarePaginator<int, OwnerCatalogueItem>
     */
    public function search(
        User $actor,
        Company $company,
        Branch $branch,
        OwnerCatalogueType $type,
        ?string $query = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->access->assertCanView($actor, $company, $branch);

        $builder = OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', $type->value)
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($query !== null && trim($query) !== '') {
            $searchColumns = $type->usesCode() ? ['name', 'code'] : ['name'];
            CaseInsensitiveLike::whereAnyContains($builder, $searchColumns, $query);
        }

        return $builder->paginate($perPage);
    }

    /**
     * Centrally seeded payment modes (Rules §5) — read-only for customers.
     *
     * @return Collection<int, PaymentMode>
     */
    public function listPaymentModes(User $actor, Company $company, Branch $branch, bool $activeOnly = true): Collection
    {
        $this->access->assertCanView($actor, $company, $branch);

        $builder = PaymentMode::query()->orderBy('sort_order');
        if ($activeOnly) {
            $builder->where('is_active', true);
        }

        return $builder->get();
    }

    private function assertOwnerItem(User $actor, OwnerCatalogueItem $item): void
    {
        if ((int) $item->owner_user_id !== (int) $actor->id) {
            throw new MasterDomainException('Catalogue item does not belong to this owner.');
        }
    }

    private function assertUniqueName(
        User $actor,
        OwnerCatalogueType $type,
        string $name,
        ?OwnerCatalogueItem $ignore = null,
    ): void {
        if ($type->usesCode()) {
            return;
        }

        $exists = OwnerCatalogueItem::query()
            ->where('owner_user_id', $actor->id)
            ->where('catalogue_type', $type->value)
            ->when($ignore !== null, fn ($q) => $q->whereKeyNot($ignore->id))
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Catalogue name already exists for this owner and type.']);
        }
    }

    private function assertUniqueCode(User $actor, OwnerCatalogueType $type, string $code): void
    {
        if (OwnerCatalogueItem::query()
            ->where('owner_user_id', $actor->id)
            ->where('catalogue_type', $type->value)
            ->where('code', $code)
            ->exists()) {
            throw ValidationException::withMessages(['code' => 'Catalogue code already exists for this owner and type.']);
        }
    }
}
