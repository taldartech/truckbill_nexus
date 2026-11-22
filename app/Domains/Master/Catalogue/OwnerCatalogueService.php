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

        $code = strtoupper(trim((string) ($attributes['code'] ?? '')));
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages([
                'code' => $code === '' ? 'Code is required.' : null,
                'name' => $name === '' ? 'Name is required.' : null,
            ]);
        }

        if (OwnerCatalogueItem::query()
            ->where('owner_user_id', $actor->id)
            ->where('catalogue_type', $type->value)
            ->where('code', $code)
            ->exists()) {
            throw ValidationException::withMessages(['code' => 'Catalogue code already exists for this owner and type.']);
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
            return $this->access->withTenant($actor, $company, $branch, function () use ($item, $attributes, $remark): OwnerCatalogueItem {
                $before = $item->toArray();
                $item->fill(collect($attributes)->only(['name', 'attributes', 'is_active', 'sort_order'])->all());
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
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($query)).'%';
            $builder->where(function ($inner) use ($term): void {
                $inner->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term);
            });
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
}
